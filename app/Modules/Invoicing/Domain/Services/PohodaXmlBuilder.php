<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Enums\Currency;
use DOMDocument;
use DOMElement;
use LogicException;

/**
 * Builds a Stormware Pohoda "dataPack" XML document (issued invoices) from
 * frozen invoice snapshots. Built via DOMDocument rather than string
 * concatenation so escaping/UTF-8 encoding is always correct.
 */
final class PohodaXmlBuilder
{
    private const NS_DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';

    private const NS_INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';

    private const NS_TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    public function __construct(
        private readonly VatRecapCalculator $recapCalculator,
    ) {}

    /**
     * @param  iterable<Invoice>  $invoices
     */
    public function build(iterable $invoices): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $dataPack = $dom->createElementNS(self::NS_DAT, 'dat:dataPack');
        $dataPack->setAttribute('id', 'QasaExport');
        $dataPack->setAttribute('application', 'Qasa Core');
        $dataPack->setAttribute('version', '2.0');
        $dataPack->setAttribute('note', 'Vydané faktúry');
        $dataPack->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:inv', self::NS_INV);
        $dataPack->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:typ', self::NS_TYP);

        $ico = null;
        $index = 0;

        foreach ($invoices as $invoice) {
            $index++;
            $ico ??= $invoice->supplier_snapshot['ico'] ?? null;

            $item = $dom->createElementNS(self::NS_DAT, 'dat:dataPackItem');
            $item->setAttribute('id', (string) $index);
            $item->setAttribute('version', '2.0');
            $item->appendChild($this->buildInvoice($dom, $invoice));

            $dataPack->appendChild($item);
        }

        if ($ico !== null) {
            $dataPack->setAttribute('ico', (string) $ico);
        }

        $dom->appendChild($dataPack);

        $xml = $dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    private function buildInvoice(DOMDocument $dom, Invoice $invoice): DOMElement
    {
        $invoiceEl = $dom->createElementNS(self::NS_INV, 'inv:invoice');
        $invoiceEl->setAttribute('version', '2.0');

        $invoiceEl->appendChild($this->buildHeader($dom, $invoice));
        $invoiceEl->appendChild($this->buildDetail($dom, $invoice));
        $invoiceEl->appendChild($this->buildSummary($dom, $invoice));

        return $invoiceEl;
    }

    private function buildHeader(DOMDocument $dom, Invoice $invoice): DOMElement
    {
        $header = $dom->createElementNS(self::NS_INV, 'inv:invoiceHeader');

        $header->appendChild($this->requiredTextEl($dom, self::NS_INV, 'inv:invoiceType', $this->invoiceTypeCode($invoice->type)));

        $number = $dom->createElementNS(self::NS_INV, 'inv:number');
        $number->appendChild($this->requiredTextEl($dom, self::NS_TYP, 'typ:numberRequested', (string) $invoice->invoice_number));
        $header->appendChild($number);

        if (($symVar = $this->textEl($dom, self::NS_INV, 'inv:symVar', $invoice->variable_symbol)) !== null) {
            $header->appendChild($symVar);
        }

        $header->appendChild($this->requiredTextEl($dom, self::NS_INV, 'inv:date', $invoice->issued_at->format('Y-m-d')));

        if ($invoice->taxable_supply_at !== null) {
            $header->appendChild($this->requiredTextEl($dom, self::NS_INV, 'inv:dateTax', $invoice->taxable_supply_at->format('Y-m-d')));
        }

        $header->appendChild($this->requiredTextEl($dom, self::NS_INV, 'inv:dateDue', $invoice->due_at->format('Y-m-d')));

        if (($partner = $this->buildPartnerIdentity($dom, $invoice->client_snapshot ?? [])) !== null) {
            $header->appendChild($partner);
        }

        $paymentType = $dom->createElementNS(self::NS_INV, 'inv:paymentType');
        $paymentType->appendChild($this->requiredTextEl(
            $dom,
            self::NS_TYP,
            'typ:ids',
            (string) config('pohoda.default_payment_type', 'draft'),
        ));
        $header->appendChild($paymentType);

        $noteParts = array_filter(
            [$invoice->note_above, $invoice->note, $this->reverseChargeNote($invoice)],
            static fn (?string $part): bool => $part !== null && $part !== '',
        );

        if ($noteParts !== []) {
            $header->appendChild($this->requiredTextEl($dom, self::NS_INV, 'inv:note', implode("\n", $noteParts)));
        }

        return $header;
    }

    /**
     * Reverse-charge invoices carry no VAT columns natively (items are
     * already 0%, so rateVAT resolves to "none" on its own) — the legal
     * clause is the only thing that needs adding, appended to the same
     * inv:note element already used for note_above/note.
     */
    private function reverseChargeNote(Invoice $invoice): ?string
    {
        if ($invoice->reverse_charge_mode === null) {
            return null;
        }

        $country = (string) ($invoice->supplier_snapshot['country'] ?? 'SK');

        return (string) __('invoices::pdf.'.$invoice->reverse_charge_mode->clauseKey($country));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function buildPartnerIdentity(DOMDocument $dom, array $snapshot): ?DOMElement
    {
        if ($snapshot === []) {
            return null;
        }

        $address = $dom->createElementNS(self::NS_TYP, 'typ:address');

        $fields = [
            'typ:company' => $snapshot['name'] ?? null,
            'typ:city' => $snapshot['city'] ?? null,
            'typ:street' => $snapshot['address'] ?? null,
            'typ:zip' => $snapshot['postal_code'] ?? null,
            'typ:ico' => $snapshot['ico'] ?? null,
            'typ:dic' => $this->dicFor($snapshot),
        ];

        foreach ($fields as $name => $value) {
            $el = $this->textEl($dom, self::NS_TYP, $name, $value !== null ? (string) $value : null);

            if ($el !== null) {
                $address->appendChild($el);
            }
        }

        $partner = $dom->createElementNS(self::NS_INV, 'inv:partnerIdentity');
        $partner->appendChild($address);

        return $partner;
    }

    /**
     * Pohoda's single `dic` field expects the VAT-prefixed identifier for
     * VAT payers; this project stores that separately as `vat_id` from the
     * plain tax id `dic`. Prefer vat_id when the party is a registered VAT
     * payer, otherwise fall back to the plain dic.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function dicFor(array $snapshot): ?string
    {
        if (($snapshot['is_vat_payer'] ?? false) && ! empty($snapshot['vat_id'])) {
            return (string) $snapshot['vat_id'];
        }

        return isset($snapshot['dic']) ? (string) $snapshot['dic'] : null;
    }

    private function invoiceTypeCode(InvoiceType $type): string
    {
        return match ($type) {
            InvoiceType::Invoice => 'issuedInvoice',
            InvoiceType::CreditNote, InvoiceType::Storno => 'issuedCreditNote',
            InvoiceType::Proforma => throw new LogicException('Proforma invoices are not exportable to Pohoda.'),
        };
    }

    private function buildDetail(DOMDocument $dom, Invoice $invoice): DOMElement
    {
        $detail = $dom->createElementNS(self::NS_INV, 'inv:invoiceDetail');
        $country = (string) ($invoice->supplier_snapshot['country'] ?? 'SK');

        foreach ($invoice->items as $item) {
            $itemEl = $dom->createElementNS(self::NS_INV, 'inv:invoiceItem');

            $itemEl->appendChild($this->requiredTextEl($dom, self::NS_INV, 'inv:text', $item->description));
            $itemEl->appendChild($this->requiredTextEl($dom, self::NS_INV, 'inv:quantity', $this->number((float) $item->quantity, 3)));
            $itemEl->appendChild($this->requiredTextEl($dom, self::NS_INV, 'inv:unit', $item->unit));
            $itemEl->appendChild($this->requiredTextEl(
                $dom,
                self::NS_INV,
                'inv:rateVAT',
                PohodaVatRate::categoryFor((float) $item->vat_rate, $country),
            ));
            $itemEl->appendChild($this->requiredTextEl($dom, self::NS_INV, 'inv:percentVAT', $this->number((float) $item->vat_rate, 2)));

            $homeCurrency = $dom->createElementNS(self::NS_INV, 'inv:homeCurrency');
            $homeCurrency->appendChild($this->requiredTextEl($dom, self::NS_TYP, 'typ:unitPrice', $this->number((float) $item->unit_price, 2)));
            $itemEl->appendChild($homeCurrency);

            $detail->appendChild($itemEl);
        }

        return $detail;
    }

    private function buildSummary(DOMDocument $dom, Invoice $invoice): DOMElement
    {
        $summary = $dom->createElementNS(self::NS_INV, 'inv:invoiceSummary');
        $country = (string) ($invoice->supplier_snapshot['country'] ?? 'SK');
        $isForeign = $invoice->currency !== Currency::CZK;

        $nativeRecap = $this->recapCalculator->recap($invoice);
        $homeRecap = $isForeign
            ? ($this->recapCalculator->czkRecap($invoice) ?? $nativeRecap)
            : $nativeRecap;

        $homeEl = $dom->createElementNS(self::NS_INV, 'inv:homeCurrency');

        foreach ($this->summaryFields($dom, $homeRecap, $country) as $field) {
            $homeEl->appendChild($field);
        }

        $summary->appendChild($homeEl);

        if ($isForeign) {
            $rate = $invoice->exchange_rate_snapshot !== null ? (float) $invoice->exchange_rate_snapshot : 1.0;

            $foreignEl = $dom->createElementNS(self::NS_INV, 'inv:foreignCurrency');
            $currencyEl = $dom->createElementNS(self::NS_TYP, 'typ:currency');
            $currencyEl->appendChild($this->requiredTextEl($dom, self::NS_TYP, 'typ:ids', $invoice->currency->value));
            $foreignEl->appendChild($currencyEl);
            $foreignEl->appendChild($this->requiredTextEl($dom, self::NS_INV, 'inv:rate', $this->number($rate, 6)));

            foreach ($this->summaryFields($dom, $nativeRecap, $country) as $field) {
                $foreignEl->appendChild($field);
            }

            $summary->appendChild($foreignEl);
        }

        return $summary;
    }

    /**
     * @param  list<VatRecapRow>  $recap
     * @return list<DOMElement>
     */
    private function summaryFields(DOMDocument $dom, array $recap, string $country): array
    {
        $base = ['none' => 0.0, 'low' => 0.0, 'third' => 0.0, 'high' => 0.0];
        $vat = ['low' => 0.0, 'third' => 0.0, 'high' => 0.0];

        foreach ($recap as $row) {
            $category = PohodaVatRate::categoryFor($row->rate, $country);
            $base[$category] += $row->base;

            if ($category !== 'none') {
                $vat[$category] += $row->vat;
            }
        }

        $elements = [$this->requiredTextEl($dom, self::NS_INV, 'inv:priceNone', $this->number($base['none'], 2))];

        foreach (['low' => 'priceLow', 'third' => 'priceHigher', 'high' => 'priceHigh'] as $category => $prefix) {
            if ($base[$category] === 0.0 && $vat[$category] === 0.0) {
                continue;
            }

            $elements[] = $this->requiredTextEl($dom, self::NS_INV, "inv:{$prefix}", $this->number($base[$category], 2));
            $elements[] = $this->requiredTextEl($dom, self::NS_INV, "inv:{$prefix}VAT", $this->number($vat[$category], 2));
            $elements[] = $this->requiredTextEl($dom, self::NS_INV, "inv:{$prefix}Sum", $this->number($base[$category] + $vat[$category], 2));
        }

        return $elements;
    }

    private function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }

    private function requiredTextEl(DOMDocument $dom, string $ns, string $qualifiedName, string $value): DOMElement
    {
        $el = $dom->createElementNS($ns, $qualifiedName);
        $el->appendChild($dom->createTextNode($value));

        return $el;
    }

    private function textEl(DOMDocument $dom, string $ns, string $qualifiedName, ?string $value): ?DOMElement
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->requiredTextEl($dom, $ns, $qualifiedName, $value);
    }
}
