<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use DOMDocument;
use DOMElement;
use LogicException;

/**
 * Builds an ISDOC 6.0.2 (https://isdoc.cz) invoice document from a frozen
 * invoice snapshot. Element order and required fields follow
 * isdoc-invoice-6.0.2.xsd exactly (verified against the published schema,
 * unlike the KROS Omega export — see config/omega.php).
 */
final class IsdocBuilder
{
    private const NS = 'http://isdoc.cz/namespace/2013';

    private const VERSION = '6.0.2';

    public function __construct(
        private readonly VatRecapCalculator $recapCalculator,
    ) {}

    public function build(Invoice $invoice): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS, 'Invoice');
        $root->setAttribute('version', self::VERSION);

        $root->appendChild($this->el($dom, 'DocumentType', $this->documentTypeCode($invoice->type)));
        // Callers only build ISDOC for already-issued invoices (drafts are
        // rejected before reaching here), so a number always exists.
        $root->appendChild($this->el($dom, 'ID', (string) $invoice->invoice_number));
        $root->appendChild($this->el($dom, 'UUID', $invoice->id));
        $root->appendChild($this->el($dom, 'IssueDate', $invoice->issued_at->format('Y-m-d')));

        if ($invoice->taxable_supply_at !== null) {
            $root->appendChild($this->el($dom, 'TaxPointDate', $invoice->taxable_supply_at->format('Y-m-d')));
        }

        $vatApplicable = (float) $invoice->vat_amount > 0.0 || $invoice->reverse_charge;
        $root->appendChild($this->el($dom, 'VATApplicable', $vatApplicable ? 'true' : 'false'));

        // Required by the schema; this project doesn't separately track
        // e-invoice consent, so it's emitted empty rather than fabricated.
        $root->appendChild($this->el($dom, 'ElectronicPossibilityAgreementReference', ''));

        $noteParts = array_filter(
            [$invoice->note_above, $invoice->note],
            static fn (?string $part): bool => $part !== null && $part !== '',
        );

        if ($noteParts !== []) {
            $root->appendChild($this->el($dom, 'Note', implode("\n", $noteParts)));
        }

        $root->appendChild($this->el($dom, 'LocalCurrencyCode', $invoice->currency->value));
        $root->appendChild($this->el($dom, 'CurrRate', '1'));
        $root->appendChild($this->el($dom, 'RefCurrRate', '1'));

        $root->appendChild($this->buildParty($dom, 'AccountingSupplierParty', $invoice->supplier_snapshot ?? []));
        $root->appendChild($this->buildParty($dom, 'AccountingCustomerParty', $invoice->client_snapshot ?? []));

        $root->appendChild($this->buildInvoiceLines($dom, $invoice));
        $root->appendChild($this->buildTaxTotal($dom, $invoice));
        $root->appendChild($this->buildLegalMonetaryTotal($dom, $invoice));

        $paymentMeans = $this->buildPaymentMeans($dom, $invoice);

        if ($paymentMeans !== null) {
            $root->appendChild($paymentMeans);
        }

        $dom->appendChild($root);

        $xml = $dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    private function documentTypeCode(InvoiceType $type): string
    {
        return match ($type) {
            InvoiceType::Invoice => '1',
            InvoiceType::CreditNote => '2',
            InvoiceType::Storno, InvoiceType::Proforma => throw new LogicException(
                'Only invoice and credit_note documents are exportable to ISDOC.'
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function buildParty(DOMDocument $dom, string $elementName, array $snapshot): DOMElement
    {
        $partyContainer = $dom->createElementNS(self::NS, $elementName);
        $party = $dom->createElementNS(self::NS, 'Party');

        $identification = $dom->createElementNS(self::NS, 'PartyIdentification');
        $identification->appendChild($this->el($dom, 'ID', (string) ($snapshot['ico'] ?? '')));
        $party->appendChild($identification);

        $partyName = $dom->createElementNS(self::NS, 'PartyName');
        $partyName->appendChild($this->el($dom, 'Name', (string) ($snapshot['name'] ?? '')));
        $party->appendChild($partyName);

        $address = $dom->createElementNS(self::NS, 'PostalAddress');
        $address->appendChild($this->el($dom, 'StreetName', (string) ($snapshot['address'] ?? '')));
        $address->appendChild($this->el($dom, 'BuildingNumber', ''));
        $address->appendChild($this->el($dom, 'CityName', (string) ($snapshot['city'] ?? '')));
        $address->appendChild($this->el($dom, 'PostalZone', (string) ($snapshot['postal_code'] ?? '')));

        $country = $dom->createElementNS(self::NS, 'Country');
        $countryCode = (string) ($snapshot['country'] ?? 'SK');
        $country->appendChild($this->el($dom, 'IdentificationCode', $countryCode));
        $country->appendChild($this->el($dom, 'Name', $countryCode));
        $address->appendChild($country);

        $party->appendChild($address);

        $dic = $this->dicFor($snapshot);

        if ($dic !== null) {
            $taxScheme = $dom->createElementNS(self::NS, 'PartyTaxScheme');
            $taxScheme->appendChild($this->el($dom, 'CompanyID', $dic));
            $taxScheme->appendChild($this->el($dom, 'TaxScheme', 'VAT'));
            $party->appendChild($taxScheme);
        }

        $partyContainer->appendChild($party);

        return $partyContainer;
    }

    /**
     * Prefer the VAT-prefixed identifier (vat_id) for registered VAT payers,
     * same precedence PohodaXmlBuilder uses — otherwise fall back to the
     * plain tax id (dic).
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function dicFor(array $snapshot): ?string
    {
        if (($snapshot['is_vat_payer'] ?? false) && ! empty($snapshot['vat_id'])) {
            return (string) $snapshot['vat_id'];
        }

        return ! empty($snapshot['dic']) ? (string) $snapshot['dic'] : null;
    }

    private function buildInvoiceLines(DOMDocument $dom, Invoice $invoice): DOMElement
    {
        $linesEl = $dom->createElementNS(self::NS, 'InvoiceLines');

        foreach ($invoice->items as $index => $item) {
            $base = round((float) $item->quantity * (float) $item->unit_price, 2);
            $vatAmount = round($base * (float) $item->vat_rate / 100, 2);
            $gross = $base + $vatAmount;
            $unitPriceGross = (float) $item->quantity !== 0.0
                ? round($gross / (float) $item->quantity, 2)
                : $gross;

            $lineEl = $dom->createElementNS(self::NS, 'InvoiceLine');
            $lineEl->appendChild($this->el($dom, 'ID', (string) ($index + 1)));

            $quantity = $dom->createElementNS(self::NS, 'InvoicedQuantity', $this->number((float) $item->quantity));
            $quantity->setAttribute('unitCode', $item->unit);
            $lineEl->appendChild($quantity);

            $lineEl->appendChild($this->el($dom, 'LineExtensionAmount', $this->number($base)));
            $lineEl->appendChild($this->el($dom, 'LineExtensionAmountTaxInclusive', $this->number($gross)));
            $lineEl->appendChild($this->el($dom, 'LineExtensionTaxAmount', $this->number($vatAmount)));
            $lineEl->appendChild($this->el($dom, 'UnitPrice', $this->number((float) $item->unit_price)));
            $lineEl->appendChild($this->el($dom, 'UnitPriceTaxInclusive', $this->number($unitPriceGross)));

            $taxCategory = $dom->createElementNS(self::NS, 'ClassifiedTaxCategory');
            $taxCategory->appendChild($this->el($dom, 'Percent', $this->number((float) $item->vat_rate)));
            // 0 = "from bottom" (base × rate), the only method this project computes.
            $taxCategory->appendChild($this->el($dom, 'VATCalculationMethod', '0'));
            $lineEl->appendChild($taxCategory);

            $itemEl = $dom->createElementNS(self::NS, 'Item');
            $itemEl->appendChild($this->el($dom, 'Description', $item->description));
            $lineEl->appendChild($itemEl);

            $linesEl->appendChild($lineEl);
        }

        return $linesEl;
    }

    private function buildTaxTotal(DOMDocument $dom, Invoice $invoice): DOMElement
    {
        $taxTotal = $dom->createElementNS(self::NS, 'TaxTotal');

        foreach ($this->recapCalculator->recap($invoice) as $row) {
            $subTotal = $dom->createElementNS(self::NS, 'TaxSubTotal');
            $subTotal->appendChild($this->el($dom, 'TaxableAmount', $this->number($row->base)));
            $subTotal->appendChild($this->el($dom, 'TaxAmount', $this->number($row->vat)));
            $subTotal->appendChild($this->el($dom, 'TaxInclusiveAmount', $this->number($row->total)));

            // No advance/deposit invoices in this export — "already claimed"
            // is always zero and the "difference" always equals the total.
            $subTotal->appendChild($this->el($dom, 'AlreadyClaimedTaxableAmount', $this->number(0.0)));
            $subTotal->appendChild($this->el($dom, 'AlreadyClaimedTaxAmount', $this->number(0.0)));
            $subTotal->appendChild($this->el($dom, 'AlreadyClaimedTaxInclusiveAmount', $this->number(0.0)));
            $subTotal->appendChild($this->el($dom, 'DifferenceTaxableAmount', $this->number($row->base)));
            $subTotal->appendChild($this->el($dom, 'DifferenceTaxAmount', $this->number($row->vat)));
            $subTotal->appendChild($this->el($dom, 'DifferenceTaxInclusiveAmount', $this->number($row->total)));

            $taxCategory = $dom->createElementNS(self::NS, 'TaxCategory');
            $taxCategory->appendChild($this->el($dom, 'Percent', $this->number($row->rate)));
            $subTotal->appendChild($taxCategory);

            $taxTotal->appendChild($subTotal);
        }

        $taxTotal->appendChild($this->el($dom, 'TaxAmount', $this->number((float) $invoice->vat_amount)));

        return $taxTotal;
    }

    private function buildLegalMonetaryTotal(DOMDocument $dom, Invoice $invoice): DOMElement
    {
        $total = $dom->createElementNS(self::NS, 'LegalMonetaryTotal');

        $subtotal = (float) $invoice->subtotal;
        $grandTotal = (float) $invoice->total;
        $balance = $invoice->balance();

        $total->appendChild($this->el($dom, 'TaxExclusiveAmount', $this->number($subtotal)));
        $total->appendChild($this->el($dom, 'TaxInclusiveAmount', $this->number($grandTotal)));

        // No advance/deposit invoices in this export — see buildTaxTotal().
        $total->appendChild($this->el($dom, 'AlreadyClaimedTaxExclusiveAmount', $this->number(0.0)));
        $total->appendChild($this->el($dom, 'AlreadyClaimedTaxInclusiveAmount', $this->number(0.0)));
        $total->appendChild($this->el($dom, 'DifferenceTaxExclusiveAmount', $this->number($subtotal)));
        $total->appendChild($this->el($dom, 'DifferenceTaxInclusiveAmount', $this->number($grandTotal)));
        $total->appendChild($this->el($dom, 'PaidDepositsAmount', $this->number(0.0)));
        $total->appendChild($this->el($dom, 'PayableAmount', $this->number($balance)));

        return $total;
    }

    /**
     * Only emitted when the frozen bank snapshot has enough to populate the
     * schema's required ID/BankCode/Name/IBAN/BIC group — PaymentMeans is
     * optional, so it's simply omitted rather than fabricating a bank code.
     */
    private function buildPaymentMeans(DOMDocument $dom, Invoice $invoice): ?DOMElement
    {
        $bank = $invoice->bank_account_snapshot ?? [];
        $accountNumber = (string) ($bank['account_number'] ?? '');
        $iban = (string) ($bank['iban'] ?? '');

        if ($iban === '' || ! str_contains($accountNumber, '/')) {
            return null;
        }

        [$localId, $bankCode] = explode('/', $accountNumber, 2);

        $paymentMeans = $dom->createElementNS(self::NS, 'PaymentMeans');
        $payment = $dom->createElementNS(self::NS, 'Payment');
        $payment->appendChild($this->el($dom, 'PaidAmount', $this->number($invoice->balance())));
        $payment->appendChild($this->el($dom, 'PaymentMeansCode', '42'));

        $details = $dom->createElementNS(self::NS, 'Details');
        $details->appendChild($this->el($dom, 'PaymentDueDate', $invoice->due_at->format('Y-m-d')));
        $details->appendChild($this->el($dom, 'ID', $localId));
        $details->appendChild($this->el($dom, 'BankCode', $bankCode));
        $details->appendChild($this->el($dom, 'Name', (string) ($bank['bank_name'] ?? '')));
        $details->appendChild($this->el($dom, 'IBAN', $iban));
        $details->appendChild($this->el($dom, 'BIC', (string) ($bank['bic'] ?? '')));

        if ($invoice->variable_symbol !== null) {
            $details->appendChild($this->el($dom, 'VariableSymbol', $invoice->variable_symbol));
        }

        $payment->appendChild($details);
        $paymentMeans->appendChild($payment);

        return $paymentMeans;
    }

    private function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function el(DOMDocument $dom, string $name, string $value): DOMElement
    {
        $el = $dom->createElementNS(self::NS, $name);
        $el->appendChild($dom->createTextNode($value));

        return $el;
    }
}
