<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ubl;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Reads a received UBL 2.1 invoice into the same suggestion shape the OCR
 * pipeline produces, so an incoming e-invoice joins the existing inbox flow
 * (review → convert) instead of getting a parallel one of its own.
 *
 * This is the point of supporting UBL on the receiving side: the fields are
 * *stated*, not guessed off a rendered page, so the suggestions are exact
 * rather than roughly four-fifths right — and the reviewer is confirming
 * rather than correcting.
 *
 * Parsing is deliberately defensive about the document itself and paranoid
 * about the parser: an inbound invoice is attacker-controlled input.
 * LIBXML_NONET plus a rejecting entity loader means no DTD, entity or import
 * can reach the network or the filesystem (the same XXE reasoning as
 * CrpdphApiClient, tightened because that one only ever talks to a known
 * endpoint and this accepts uploads).
 */
final class Ubl21InvoiceParser
{
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const ROOT_NAMESPACES = [
        'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
    ];

    /**
     * True when the payload looks like a UBL invoice or credit note.
     *
     * Checked before parsing so the inbox can fall back to OCR for anything
     * else — an XML attachment is not necessarily an e-invoice.
     */
    public function looksLikeUbl(string $xml): bool
    {
        return $this->load($xml) !== null;
    }

    /**
     * @return array<string, mixed>|null Suggestions in the inbox's shape, or
     *                                   null when this is not a UBL document
     */
    public function parse(string $xml): ?array
    {
        $dom = $this->load($xml);

        if ($dom === null) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cac', self::NS_CAC);
        $xpath->registerNamespace('cbc', self::NS_CBC);

        $supplier = '/*/cac:AccountingSupplierParty/cac:Party';

        $suggestions = [
            'supplier_invoice_number' => $this->string($xpath, '/*/cbc:ID'),
            'ico' => $this->string($xpath, $supplier.'/cac:PartyLegalEntity/cbc:CompanyID'),
            'vat_id' => $this->string($xpath, $supplier.'/cac:PartyTaxScheme/cbc:CompanyID'),
            'issued_at' => $this->string($xpath, '/*/cbc:IssueDate'),
            'due_at' => $this->string($xpath, '/*/cbc:DueDate'),
            'taxable_supply_at' => $this->string($xpath, '/*/cbc:TaxPointDate'),
            'total' => $this->number($xpath, '/*/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'),
            'currency' => $this->string($xpath, '/*/cbc:DocumentCurrencyCode'),
            'variable_symbol' => $this->string($xpath, '/*/cac:PaymentMeans/cbc:PaymentID')
                ?? $this->string($xpath, '/*/cbc:BuyerReference'),
            'iban' => $this->string($xpath, '/*/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID'),
            'vat_breakdown' => $this->vatBreakdown($xpath),
            'line_items' => $this->lineItems($xpath),
        ];

        // dic is not a UBL concept — only a VAT identifier is — so it stays
        // absent rather than being filled with the VAT id under a name that
        // means something else in SK/CZ paperwork.
        return array_filter(
            $suggestions,
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );
    }

    /**
     * @return list<array{rate: float, base: float, vat: float}>
     */
    private function vatBreakdown(DOMXPath $xpath): array
    {
        $rows = [];

        foreach ($this->nodes($xpath, '/*/cac:TaxTotal/cac:TaxSubtotal') as $subtotal) {
            $rows[] = [
                'rate' => $this->number($xpath, 'cac:TaxCategory/cbc:Percent', $subtotal) ?? 0.0,
                'base' => $this->number($xpath, 'cbc:TaxableAmount', $subtotal) ?? 0.0,
                'vat' => $this->number($xpath, 'cbc:TaxAmount', $subtotal) ?? 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{description: string, quantity: float, unit_price: float, vat_rate: float, amount: float}>
     */
    private function lineItems(DOMXPath $xpath): array
    {
        $rows = [];

        // Invoice lines and credit-note lines are different element names for
        // the same thing; the union keeps one code path.
        foreach ($this->nodes($xpath, '/*/cac:InvoiceLine | /*/cac:CreditNoteLine') as $line) {
            $rows[] = [
                'description' => $this->string($xpath, 'cac:Item/cbc:Name', $line) ?? '',
                'quantity' => $this->number($xpath, 'cbc:InvoicedQuantity', $line)
                    ?? $this->number($xpath, 'cbc:CreditedQuantity', $line)
                    ?? 0.0,
                'unit_price' => $this->number($xpath, 'cac:Price/cbc:PriceAmount', $line) ?? 0.0,
                'vat_rate' => $this->number($xpath, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $line)
                    ?? $this->number($xpath, 'cac:Item/cac:TaxCategory/cbc:Percent', $line)
                    ?? 0.0,
                'amount' => $this->number($xpath, 'cbc:LineExtensionAmount', $line) ?? 0.0,
            ];
        }

        return $rows;
    }

    private function load(string $xml): ?DOMDocument
    {
        if (trim($xml) === '') {
            return null;
        }

        $previousErrors = libxml_use_internal_errors(true);

        // Refuse every external entity outright. LIBXML_NONET alone blocks
        // the network but not a local file:// reference, and this input
        // arrives from outside.
        //
        // The setter reports success rather than handing back the previous
        // loader, so there is nothing to put back afterwards — the finally
        // block clears it to the default instead. Nothing else in this
        // codebase installs one.
        libxml_set_external_entity_loader(static fn (): null => null);

        try {
            $dom = new DOMDocument;

            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOENT);

            if ($loaded === false || $dom->documentElement === null) {
                return null;
            }

            return in_array($dom->documentElement->namespaceURI, self::ROOT_NAMESPACES, true)
                ? $dom
                : null;
        } finally {
            libxml_clear_errors();
            libxml_set_external_entity_loader(null);
            libxml_use_internal_errors($previousErrors);
        }
    }

    /**
     * @return list<DOMElement>
     */
    private function nodes(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        $result = $context === null ? $xpath->query($expression) : $xpath->query($expression, $context);

        if ($result === false) {
            return [];
        }

        $nodes = [];

        foreach ($result as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function string(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?string
    {
        $node = $this->nodes($xpath, $expression, $context)[0] ?? null;

        if ($node === null) {
            return null;
        }

        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    private function number(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?float
    {
        $value = $this->string($xpath, $expression, $context);

        return $value === null || ! is_numeric($value) ? null : (float) $value;
    }
}
