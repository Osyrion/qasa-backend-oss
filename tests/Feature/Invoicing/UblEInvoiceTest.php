<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Infrastructure\Ubl\Ubl21InvoiceParser;
use App\Modules\Shared\Exceptions\DomainException;

require_once __DIR__.'/../../Support/UblFixtures.php';

/**
 * Validates against the **official OASIS UBL 2.1 schema set**, vendored under
 * tests/Fixtures/ubl (same convention as the ISDOC and VAT-return XSDs).
 *
 * What this does not prove: the EN 16931 Schematron rules (BR-*, BR-CO-*)
 * and Peppol BIS. The XSD is far looser than the norm — a document can pass
 * it and still break a business rule. Saying "EN 16931 compliant" to a
 * customer needs the Schematron pass and a real access point, which is F3.
 */
function ublSchemaPath(string $root): string
{
    return dirname(__DIR__, 2)."/Fixtures/ubl/maindoc/UBL-{$root}-2.1.xsd";
}

function assertValidUbl(string $xml, string $root = 'Invoice'): void
{
    $previous = libxml_use_internal_errors(true);

    $dom = new DOMDocument;
    $dom->loadXML($xml);

    $valid = $dom->schemaValidate(ublSchemaPath($root));

    $messages = array_map(
        static fn (LibXMLError $error): string => trim($error->message),
        libxml_get_errors(),
    );

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($valid)->toBeTrue(implode("\n", $messages));
}

function ublXpath(string $xml): DOMXPath
{
    $dom = new DOMDocument;
    $dom->loadXML($xml);

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

    return $xpath;
}

function ublValue(string $xml, string $expression): ?string
{
    $nodes = ublXpath($xml)->query($expression);

    return $nodes !== false && $nodes->length > 0 ? trim((string) $nodes->item(0)?->textContent) : null;
}

it('exports an invoice that validates against the official UBL 2.1 schema', function (): void {
    assertValidUbl(buildUbl(ublInvoice()));
});

it('exports a credit note as a UBL CreditNote document', function (): void {
    // One owner for both: the credited invoice has to be readable from the
    // credit note's own account, or the reference falls back to a raw id.
    $owner = createUser(['country' => 'SK']);
    $original = ublInvoice(owner: $owner);

    $creditNote = ublInvoice([
        'type' => InvoiceType::CreditNote->value,
        'invoice_number' => 'DO-2026-001',
        'related_invoice_id' => $original->id,
    ], owner: $owner);

    $xml = buildUbl($creditNote);

    // A credit note is a different UBL document, not a flag on an invoice —
    // so it has to validate against the other schema entirely.
    assertValidUbl($xml, 'CreditNote');

    expect(ublValue($xml, '/*/cbc:CreditNoteTypeCode'))->toBe('381')
        ->and(ublValue($xml, '/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID'))->toBe('FA-2026-001');
});

it('carries the EN 16931 business terms a recipient needs', function (): void {
    $xml = buildUbl(ublInvoice());

    expect(ublValue($xml, '/*/cbc:CustomizationID'))->toBe('urn:cen.eu:en16931:2017')   // BT-24
        ->and(ublValue($xml, '/*/cbc:ID'))->toBe('FA-2026-001')                          // BT-1
        ->and(ublValue($xml, '/*/cbc:IssueDate'))->not->toBeNull()                       // BT-2
        ->and(ublValue($xml, '/*/cbc:DueDate'))->not->toBeNull()                         // BT-9
        ->and(ublValue($xml, '/*/cbc:InvoiceTypeCode'))->toBe('380')                     // BT-3
        ->and(ublValue($xml, '/*/cbc:DocumentCurrencyCode'))->toBe('EUR')                // BT-5
        ->and(ublValue($xml, '/*/cac:AccountingSupplierParty//cbc:RegistrationName'))->toBe('Dodávateľ s.r.o.') // BT-27
        ->and(ublValue($xml, '/*/cac:AccountingCustomerParty//cbc:RegistrationName'))->toBe('Odberateľ a.s.')   // BT-44
        ->and(ublValue($xml, '/*/cac:AccountingSupplierParty//cac:PartyTaxScheme/cbc:CompanyID'))->toBe('SK1020304050') // BT-31
        ->and(ublValue($xml, '/*/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID'))->toBe('SK3112000000198742637541') // BT-84
        ->and(ublValue($xml, '/*/cac:PaymentMeans/cbc:PaymentID'))->toBe('2026001');     // BT-83
});

it('maps the item unit to a UN/ECE Rec 20 code', function (): void {
    $xml = buildUbl(ublInvoice());

    $nodes = ublXpath($xml)->query('/*/cac:InvoiceLine/cbc:InvoicedQuantity');
    $node = $nodes === false ? null : $nodes->item(0);

    // "hod" is not a code list value; HUR is. An out-of-list unitCode is
    // rejected at the receiving end, where nobody can fix it.
    expect($node)->toBeInstanceOf(DOMElement::class);
    assert($node instanceof DOMElement);

    expect($node->getAttribute('unitCode'))->toBe('HUR');
});

it('reports the exact totals the invoice holds, never a recomputation', function (): void {
    $invoice = ublInvoice();
    $xml = buildUbl($invoice);

    expect(ublValue($xml, '/*/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'))
        ->toBe(number_format((float) $invoice->subtotal, 2, '.', ''))
        ->and(ublValue($xml, '/*/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'))
        ->toBe(number_format((float) $invoice->total, 2, '.', ''))
        ->and(ublValue($xml, '/*/cac:TaxTotal/cbc:TaxAmount'))
        ->toBe(number_format((float) $invoice->vat_amount, 2, '.', ''));
});

it('maps every VAT situation to its UNCL 5305 category', function (
    array $attributes,
    float $rate,
    bool $vatPayer,
    string $expected,
): void {
    $invoice = ublInvoice($attributes, $rate);

    if (! $vatPayer) {
        $snapshot = $invoice->supplier_snapshot;
        $snapshot['is_vat_payer'] = false;
        $snapshot['vat_id'] = null;
        $invoice->update(['supplier_snapshot' => $snapshot]);
    }

    $xml = buildUbl($invoice);

    expect(ublValue($xml, '/*/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:ID'))->toBe($expected);
})->with([
    'standard rate' => [[], 23.0, true, 'S'],
    'reduced rate is still S' => [[], 10.0, true, 'S'],
    'zero rated' => [[], 0.0, true, 'Z'],
    'domestic reverse charge' => [['reverse_charge' => true, 'reverse_charge_mode' => ReverseChargeMode::Domestic->value], 0.0, true, 'AE'],
    'intra-community' => [['reverse_charge' => true, 'reverse_charge_mode' => ReverseChargeMode::Eu->value], 0.0, true, 'K'],
    'supplier not VAT registered' => [[], 0.0, false, 'E'],
]);

it('states a reason for every category that is not the standard one', function (): void {
    $xml = buildUbl(ublInvoice([
        'reverse_charge' => true,
        'reverse_charge_mode' => ReverseChargeMode::Domestic->value,
    ], 0.0));

    // BT-120: an exemption without a stated reason is what a receiving system
    // rejects, and the reason is translated rather than hardcoded.
    expect(ublValue($xml, '/*/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReason'))
        ->not->toBeNull();
});

it('refuses to export a proforma as an e-invoice', function (): void {
    // A proforma is not a tax document; emitting it as a 380 would be a
    // false statement to the recipient.
    expect(fn () => buildUbl(ublInvoice(['type' => InvoiceType::Proforma->value])))
        ->toThrow(DomainException::class);
});

it('serves the export over the API', function (): void {
    // actingAs the edition's own user model — $invoice->user is the core
    // class, which carries no roles and would be refused by the policy.
    $owner = createUser(['country' => 'SK']);
    $invoice = ublInvoice(owner: $owner);

    $response = $this->actingAs($owner)
        ->get("/api/v1/invoices/{$invoice->id}/export/ubl")
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/xml');
    assertValidUbl((string) $response->getContent());
});

// ── Import ───────────────────────────────────────────────────────────────────

it('reads its own export back with the numbers intact', function (): void {
    $invoice = ublInvoice();
    $xml = buildUbl($invoice);

    $parsed = app(Ubl21InvoiceParser::class)->parse($xml) ?? [];

    expect($parsed['supplier_invoice_number'] ?? null)->toBe('FA-2026-001')
        ->and($parsed['ico'] ?? null)->toBe('12345678')
        ->and($parsed['vat_id'] ?? null)->toBe('SK1020304050')
        ->and($parsed['currency'] ?? null)->toBe('EUR')
        ->and($parsed['iban'] ?? null)->toBe('SK3112000000198742637541')
        ->and($parsed['variable_symbol'] ?? null)->toBe('2026001')
        ->and($parsed['total'] ?? null)->toBe((float) $invoice->total)
        ->and($parsed['vat_breakdown'][0]['vat'] ?? null)->toBe((float) $invoice->vat_amount)
        ->and($parsed['line_items'][0]['description'] ?? null)->toBe('Konzultácie');
});

it('reads a credit note back through the same path', function (): void {
    $creditNote = ublInvoice(['type' => InvoiceType::CreditNote->value, 'invoice_number' => 'DO-1']);

    $parsed = app(Ubl21InvoiceParser::class)->parse(buildUbl($creditNote)) ?? [];

    expect($parsed['supplier_invoice_number'] ?? null)->toBe('DO-1')
        ->and($parsed['line_items'] ?? [])->toHaveCount(1);
});

it('rejects XML that is not a UBL invoice', function (): void {
    $parser = app(Ubl21InvoiceParser::class);

    expect($parser->parse('<foo/>'))->toBeNull()
        ->and($parser->parse('not xml at all'))->toBeNull()
        ->and($parser->parse(''))->toBeNull()
        ->and($parser->looksLikeUbl('<foo/>'))->toBeFalse();
});

it('never resolves an external entity in an incoming invoice', function (): void {
    // XXE: an inbound e-invoice is attacker-controlled input, so a DOCTYPE
    // pointing at a local file must not be expanded into the parsed values.
    $xml = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <!DOCTYPE Invoice [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
    <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
             xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
      <cbc:ID>&xxe;</cbc:ID>
    </Invoice>
    XML;

    $parsed = app(Ubl21InvoiceParser::class)->parse($xml) ?? [];

    $number = $parsed['supplier_invoice_number'] ?? '';

    expect($number)->not->toContain('root:');
});
