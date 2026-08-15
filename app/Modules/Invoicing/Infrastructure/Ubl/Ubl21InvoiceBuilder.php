<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ubl;

use App\Modules\Invoicing\Application\Contracts\UblInvoiceBuilderInterface;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Shared\Exceptions\DomainException;
use DOMDocument;
use DOMElement;

/**
 * OASIS UBL 2.1 invoice built to the EN 16931 semantic model — the invoice's
 * own legal wire format, as opposed to the accounting-software exports
 * (Pohoda/Omega/ISDOC) that live in the premium Accounting module.
 *
 * Core, deliberately: once structured e-invoicing is mandatory a self-hosted
 * account cannot legally invoice without this, so the OSS edition would stop
 * being a usable product. It is also residency-neutral — EN 16931 is a
 * European norm both SK and CZ adopt — so it sits outside the TaxSystem
 * strategy, and national CIUS variants are a matter of the CustomizationID
 * in config rather than a branch in this class.
 *
 * A credit note is a different UBL *document*, not a flag: hence two roots
 * and the tag/typecode differences threaded through below.
 *
 * Every monetary figure is read from what the document already stores. The
 * ISDOC builder learned this the hard way and wrote it down: recomputing is
 * exactly how an export starts to disagree with the invoice it represents,
 * and a golden test cannot catch it because it only compares the export
 * against itself.
 */
final class Ubl21InvoiceBuilder implements UblInvoiceBuilderInterface
{
    private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /** UNCL 1001: commercial invoice / credit note. */
    private const TYPE_CODE_INVOICE = '380';

    private const TYPE_CODE_CREDIT_NOTE = '381';

    /** UNTDID 4461: credit transfer — the only payment means this product issues. */
    private const PAYMENT_MEANS_CREDIT_TRANSFER = '30';

    public function __construct(
        private readonly VatRecapCalculator $recapCalculator,
    ) {}

    public function format(): string
    {
        return 'ubl';
    }

    /**
     * @throws DomainException when the document type has no UBL equivalent
     */
    public function build(Invoice $invoice): string
    {
        $isCreditNote = $invoice->type === InvoiceType::CreditNote;

        if (! $isCreditNote && $invoice->type !== InvoiceType::Invoice) {
            // A proforma is not a tax document and a storno is a domestic
            // bookkeeping construct with no UNCL 1001 code; emitting either
            // as a 380 would be a false statement to the recipient.
            throw DomainException::because(__('invoicing.ubl_unsupported_document_type'));
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $rootName = $isCreditNote ? 'CreditNote' : 'Invoice';
        $root = $dom->createElementNS($isCreditNote ? self::NS_CREDIT_NOTE : self::NS_INVOICE, $rootName);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);

        // Attached before the children are built, not after — a document
        // element belongs in its document.
        //
        // libxml still re-declares xmlns:cac/xmlns:cbc on nodes built while
        // their own parent was detached, so the payload carries about a
        // kilobyte of redundant declarations. Harmless and schema-valid
        // (the official EN 16931 Schematron passes either way); removing it
        // would mean every helper appending into its parent before filling
        // it, which is a larger change than the noise is worth.
        $dom->appendChild($root);

        $currency = $invoice->currency->value;

        // BT-24 / BT-23. Both configurable so a national CIUS (or Peppol BIS)
        // is a deployment setting rather than a code change.
        $root->appendChild($this->cbc($dom, 'CustomizationID', (string) config('invoicing.ubl.customization_id')));
        $root->appendChild($this->cbc($dom, 'ProfileID', (string) config('invoicing.ubl.profile_id')));

        // BT-1. Callers only export issued documents, so a number exists.
        $root->appendChild($this->cbc($dom, 'ID', (string) $invoice->invoice_number));
        $root->appendChild($this->cbc($dom, 'IssueDate', $invoice->issued_at->format('Y-m-d')));

        // BT-9. UBL puts DueDate on Invoice only; a credit note carries it
        // through PaymentTerms instead, which is why this is conditional.
        if (! $isCreditNote) {
            $root->appendChild($this->cbc($dom, 'DueDate', $invoice->due_at->format('Y-m-d')));
        }

        $root->appendChild($this->cbc(
            $dom,
            $isCreditNote ? 'CreditNoteTypeCode' : 'InvoiceTypeCode',
            $isCreditNote ? self::TYPE_CODE_CREDIT_NOTE : self::TYPE_CODE_INVOICE,
        ));

        foreach ([$invoice->note_above, $invoice->note] as $note) {
            if ($note !== null && $note !== '') {
                $root->appendChild($this->cbc($dom, 'Note', $note));
            }
        }

        // BT-7, the date the VAT becomes chargeable (DUZP).
        if ($invoice->taxable_supply_at !== null) {
            $root->appendChild($this->cbc($dom, 'TaxPointDate', $invoice->taxable_supply_at->format('Y-m-d')));
        }

        $root->appendChild($this->cbc($dom, 'DocumentCurrencyCode', $currency));

        if ($invoice->variable_symbol !== null && $invoice->variable_symbol !== '') {
            // BT-13: the reference the buyer quotes back on payment. The
            // variable symbol is exactly that in SK/CZ practice.
            $root->appendChild($this->cbc($dom, 'BuyerReference', $invoice->variable_symbol));
        }

        if ($isCreditNote && $invoice->related_invoice_id !== null) {
            $reference = $dom->createElementNS(self::NS_CAC, 'cac:BillingReference');
            $documentReference = $dom->createElementNS(self::NS_CAC, 'cac:InvoiceDocumentReference');
            // BT-25: the invoice being credited, by its human number where
            // the relation is loadable and by id otherwise — the recipient
            // matches on the number they were originally sent.
            $originalNumber = $invoice->relatedInvoice?->invoice_number;

            $documentReference->appendChild($this->cbc(
                $dom,
                'ID',
                $originalNumber ?? $invoice->related_invoice_id,
            ));
            $reference->appendChild($documentReference);
            $root->appendChild($reference);
        }

        $root->appendChild($this->party($dom, 'cac:AccountingSupplierParty', $invoice->supplier_snapshot ?? []));
        $root->appendChild($this->party($dom, 'cac:AccountingCustomerParty', $invoice->client_snapshot ?? []));

        $paymentMeans = $this->paymentMeans($dom, $invoice);

        if ($paymentMeans !== null) {
            $root->appendChild($paymentMeans);
        }

        if ($isCreditNote) {
            $terms = $dom->createElementNS(self::NS_CAC, 'cac:PaymentTerms');
            $terms->appendChild($this->cbc($dom, 'Note', __('invoicing.ubl_credit_note_terms', [
                'date' => $invoice->due_at->format('d.m.Y'),
            ])));
            $root->appendChild($terms);
        }

        // Before TaxTotal: UBL fixes this order, and the tax subtotals below
        // are only reconcilable against these allowances.
        foreach ($this->documentAllowances($dom, $invoice, $currency) as $allowance) {
            $root->appendChild($allowance);
        }

        $root->appendChild($this->taxTotal($dom, $invoice, $currency));
        $root->appendChild($this->legalMonetaryTotal($dom, $invoice, $currency));

        foreach ($invoice->items as $index => $item) {
            $root->appendChild($this->line($dom, $invoice, $item, $index + 1, $currency, $isCreditNote));
        }

        $xml = $dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function party(DOMDocument $dom, string $containerName, array $snapshot): DOMElement
    {
        $container = $dom->createElementNS(self::NS_CAC, $containerName);
        $party = $dom->createElementNS(self::NS_CAC, 'cac:Party');

        $countryCode = (string) ($snapshot['country'] ?? '');
        $vatId = $this->vatIdFor($snapshot);

        // BT-34/BT-49: the address the network delivers to.
        $address = $this->electronicAddress($snapshot);

        if ($address !== null) {
            $endpoint = $this->cbc($dom, 'EndpointID', $address[1]);
            $endpoint->setAttribute('schemeID', $address[0]);
            $party->appendChild($endpoint);
        }

        $name = $dom->createElementNS(self::NS_CAC, 'cac:PartyName');
        $name->appendChild($this->cbc($dom, 'Name', (string) ($snapshot['name'] ?? '')));
        $party->appendChild($name);

        $address = $dom->createElementNS(self::NS_CAC, 'cac:PostalAddress');
        $address->appendChild($this->cbc($dom, 'StreetName', (string) ($snapshot['address'] ?? '')));
        $address->appendChild($this->cbc($dom, 'CityName', (string) ($snapshot['city'] ?? '')));
        $address->appendChild($this->cbc($dom, 'PostalZone', (string) ($snapshot['postal_code'] ?? '')));

        $country = $dom->createElementNS(self::NS_CAC, 'cac:Country');
        $country->appendChild($this->cbc($dom, 'IdentificationCode', $countryCode));
        $address->appendChild($country);
        $party->appendChild($address);

        // BT-31/BT-48: the VAT identifier, and only that — a plain tax number
        // (DIČ) is not a VAT identifier and must not be presented as one.
        if ($vatId !== null) {
            $taxScheme = $dom->createElementNS(self::NS_CAC, 'cac:PartyTaxScheme');
            $taxScheme->appendChild($this->cbc($dom, 'CompanyID', $vatId));
            $scheme = $dom->createElementNS(self::NS_CAC, 'cac:TaxScheme');
            $scheme->appendChild($this->cbc($dom, 'ID', 'VAT'));
            $taxScheme->appendChild($scheme);
            $party->appendChild($taxScheme);
        }

        // BT-27/BT-44 (registered name) and BT-30/BT-47 (registration id).
        $legalEntity = $dom->createElementNS(self::NS_CAC, 'cac:PartyLegalEntity');
        $legalEntity->appendChild($this->cbc($dom, 'RegistrationName', (string) ($snapshot['name'] ?? '')));

        $ico = (string) ($snapshot['ico'] ?? '');

        if ($ico !== '') {
            $legalEntity->appendChild($this->cbc($dom, 'CompanyID', $ico));
        }

        $party->appendChild($legalEntity);

        $container->appendChild($party);

        return $container;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function vatIdFor(array $snapshot): ?string
    {
        $vatId = (string) ($snapshot['vat_id'] ?? '');

        return $vatId !== '' ? $vatId : null;
    }

    /**
     * ISO 6523 scheme for the electronic address: the VAT-number schemes
     * (9944 SK, 9930 CZ) fall back to 9925 elsewhere, which is the generic
     * "VAT number" identifier.
     */
    /**
     * BT-34/BT-49 — the party's electronic address, as `[schemeID, value]`.
     *
     * This was wrong in every branch until 2026-08-13. It used ISO 6523 codes
     * 9944 for SK, 9930 for CZ and 9925 as a "generic VAT number" fallback;
     * in the Peppol EAS code list those are the **Netherlands**, **Germany**
     * and **Belgium** VAT schemes. Every document we produced therefore
     * declared a foreign electronic address — not a formatting slip, but the
     * field the network routes on.
     *
     * The order below is deliberate:
     *
     * 1. An explicitly recorded participant id wins. It is what the account
     *    hands its access point for routing, so anything else here would make
     *    the document contradict the envelope carrying it.
     * 2. SK derives `0245` + DIČ. That is the identifier the Slovak mandate
     *    is built on (Financial Directorate = Peppol Authority SK), and the
     *    DIČ is already on the document, so it needs no extra data entry.
     * 3. CZ derives `9929` (Czech Republic VAT number).
     * 4. Anything else yields nothing. There is no generic VAT scheme in the
     *    code list to fall back on — 0199 is the LEI, not a VAT identifier —
     *    and an absent address fails Peppol validation loudly (R010/R020)
     *    where a guessed one would quietly route a legal document to a
     *    stranger.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{0: string, 1: string}|null
     */
    private function electronicAddress(array $snapshot): ?array
    {
        $peppolId = trim((string) ($snapshot['peppol_id'] ?? ''));

        if (preg_match('/^(\d{4}):(.+)$/', $peppolId, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        $country = mb_strtoupper((string) ($snapshot['country'] ?? ''));
        $dic = trim((string) ($snapshot['dic'] ?? ''));
        $vatId = $this->vatIdFor($snapshot);

        if ($country === 'SK' && $dic !== '') {
            return ['0245', $dic];
        }

        if ($country === 'CZ' && $vatId !== null) {
            return ['9929', $vatId];
        }

        return null;
    }

    private function paymentMeans(DOMDocument $dom, Invoice $invoice): ?DOMElement
    {
        $bank = $invoice->bank_account_snapshot ?? [];
        $iban = (string) ($bank['iban'] ?? '');

        if ($iban === '') {
            return null;
        }

        $means = $dom->createElementNS(self::NS_CAC, 'cac:PaymentMeans');
        $means->appendChild($this->cbc($dom, 'PaymentMeansCode', self::PAYMENT_MEANS_CREDIT_TRANSFER));

        if ($invoice->variable_symbol !== null && $invoice->variable_symbol !== '') {
            // BT-83: what the payer puts on the transfer so it can be matched.
            $means->appendChild($this->cbc($dom, 'PaymentID', $invoice->variable_symbol));
        }

        $account = $dom->createElementNS(self::NS_CAC, 'cac:PayeeFinancialAccount');
        $account->appendChild($this->cbc($dom, 'ID', $iban));

        $bic = (string) ($bank['bic'] ?? '');

        if ($bic !== '') {
            $branch = $dom->createElementNS(self::NS_CAC, 'cac:FinancialInstitutionBranch');
            $branch->appendChild($this->cbc($dom, 'ID', $bic));
            $account->appendChild($branch);
        }

        $means->appendChild($account);

        return $means;
    }

    private function taxTotal(DOMDocument $dom, Invoice $invoice, string $currency): DOMElement
    {
        $taxTotal = $dom->createElementNS(self::NS_CAC, 'cac:TaxTotal');
        $taxTotal->appendChild($this->amount($dom, 'TaxAmount', (float) $invoice->vat_amount, $currency));

        foreach ($this->recapCalculator->recap($invoice) as $row) {
            $subtotal = $dom->createElementNS(self::NS_CAC, 'cac:TaxSubtotal');
            $subtotal->appendChild($this->amount($dom, 'TaxableAmount', $row->base, $currency));
            $subtotal->appendChild($this->amount($dom, 'TaxAmount', $row->vat, $currency));
            $subtotal->appendChild($this->taxCategory($dom, $invoice, $row->rate));
            $taxTotal->appendChild($subtotal);
        }

        return $taxTotal;
    }

    /**
     * $elementName differs by position: a tax subtotal carries
     * cac:TaxCategory, an item carries cac:ClassifiedTaxCategory. Same
     * content model, two names — the schema rejects either in the other's
     * place.
     */
    private function taxCategory(DOMDocument $dom, Invoice $invoice, float $rate, string $elementName = 'cac:TaxCategory'): DOMElement
    {
        $category = VatCategoryMap::categoryFor($invoice, $rate);

        $el = $dom->createElementNS(self::NS_CAC, $elementName);
        $el->appendChild($this->cbc($dom, 'ID', $category));
        $el->appendChild($this->cbc($dom, 'Percent', $this->number($rate)));

        $reason = VatCategoryMap::exemptionReasonFor($category);

        if ($reason !== null) {
            $el->appendChild($this->cbc($dom, 'TaxExemptionReason', $reason));
        }

        $scheme = $dom->createElementNS(self::NS_CAC, 'cac:TaxScheme');
        $scheme->appendChild($this->cbc($dom, 'ID', 'VAT'));
        $el->appendChild($scheme);

        return $el;
    }

    /**
     * BT-92: the document-level discount, as one allowance per VAT rate.
     *
     * EN 16931 has no lump-sum discount. An allowance carries its own VAT
     * category and rate, and BR-S-08 reconciles each rate's taxable amount
     * against that rate's lines minus that rate's allowances — so a single
     * header percentage has to be resolved into per-rate allowances or the
     * document is simply invalid. It was, until 2026-08-13: AllowanceTotalAmount
     * was written with nothing to back it, which BR-CO-11 rejects.
     *
     * The split comes from VatRecapCalculator, which already performs it for
     * the recap; recomputing it here would be a second opinion on the same
     * money.
     *
     * @return list<DOMElement>
     */
    private function documentAllowances(DOMDocument $dom, Invoice $invoice, string $currency): array
    {
        $allowances = [];

        foreach ($this->recapCalculator->discountByRate($invoice) as $rate => $amount) {
            $el = $dom->createElementNS(self::NS_CAC, 'cac:AllowanceCharge');

            // false = allowance (a deduction). true would make it a charge,
            // i.e. the same number added instead of subtracted.
            $el->appendChild($this->cbc($dom, 'ChargeIndicator', 'false'));
            $el->appendChild($this->cbc($dom, 'AllowanceChargeReason', (string) __('invoicing.ubl_document_discount')));
            $el->appendChild($this->amount($dom, 'Amount', $amount, $currency));
            $el->appendChild($this->taxCategory($dom, $invoice, (float) $rate));

            $allowances[] = $el;
        }

        return $allowances;
    }

    private function legalMonetaryTotal(DOMDocument $dom, Invoice $invoice, string $currency): DOMElement
    {
        $total = $dom->createElementNS(self::NS_CAC, 'cac:LegalMonetaryTotal');

        // `subtotal` is already the sum of the line amounts *before* the
        // document-level discount — which is BT-106 exactly. Adding the
        // discount back on top (as this did until 2026-08-13) overstated it
        // by the discount and broke BR-CO-10, while BT-109 was left at the
        // undiscounted figure and broke BR-CO-15.
        $lineExtension = (float) $invoice->subtotal;
        $taxExclusive = $lineExtension - (float) $invoice->discount_amount;
        $taxInclusive = (float) $invoice->total;

        // BT-106 is the sum of the line amounts, before the document-level
        // discount; BT-109 is after it. They differ exactly by BT-107.
        $total->appendChild($this->amount($dom, 'LineExtensionAmount', $lineExtension, $currency));
        $total->appendChild($this->amount($dom, 'TaxExclusiveAmount', $taxExclusive, $currency));
        $total->appendChild($this->amount($dom, 'TaxInclusiveAmount', $taxInclusive, $currency));

        if ((float) $invoice->discount_amount > 0.0) {
            $total->appendChild($this->amount($dom, 'AllowanceTotalAmount', (float) $invoice->discount_amount, $currency));
        }

        $paid = $taxInclusive - $invoice->balance();

        if ($paid > 0.0) {
            $total->appendChild($this->amount($dom, 'PrepaidAmount', $paid, $currency));
        }

        // BT-115: what is actually still owed, which is the number the
        // recipient's payment run reads.
        $total->appendChild($this->amount($dom, 'PayableAmount', $invoice->balance(), $currency));

        return $total;
    }

    private function line(
        DOMDocument $dom,
        Invoice $invoice,
        InvoiceItem $item,
        int $number,
        string $currency,
        bool $isCreditNote,
    ): DOMElement {
        $el = $dom->createElementNS(self::NS_CAC, $isCreditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine');
        $el->appendChild($this->cbc($dom, 'ID', (string) $number));

        $quantity = $this->cbc(
            $dom,
            $isCreditNote ? 'CreditedQuantity' : 'InvoicedQuantity',
            $this->number((float) $item->quantity),
        );
        $quantity->setAttribute('unitCode', UnitCodeMap::toUnece($item->unit));
        $el->appendChild($quantity);

        $el->appendChild($this->amount($dom, 'LineExtensionAmount', (float) $item->total_excl_vat, $currency));

        $itemEl = $dom->createElementNS(self::NS_CAC, 'cac:Item');
        $itemEl->appendChild($this->cbc($dom, 'Name', $item->description));
        $itemEl->appendChild($this->taxCategory($dom, $invoice, (float) $item->vat_rate, 'cac:ClassifiedTaxCategory'));
        $el->appendChild($itemEl);

        $price = $dom->createElementNS(self::NS_CAC, 'cac:Price');
        $price->appendChild($this->amount($dom, 'PriceAmount', (float) $item->unit_price, $currency));
        $el->appendChild($price);

        return $el;
    }

    private function amount(DOMDocument $dom, string $name, float $value, string $currency): DOMElement
    {
        $el = $this->cbc($dom, $name, $this->number($value));
        $el->setAttribute('currencyID', $currency);

        return $el;
    }

    private function cbc(DOMDocument $dom, string $name, string $value): DOMElement
    {
        $el = $dom->createElementNS(self::NS_CBC, 'cbc:'.$name);
        $el->appendChild($dom->createTextNode($value));

        return $el;
    }

    private function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
