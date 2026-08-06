<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Contracts\ProcessInboxFileActionInterface;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use Illuminate\Support\Facades\Storage;

/**
 * A received e-invoice joins the existing inbox flow rather than getting one
 * of its own — the reviewer confirms exact values instead of correcting
 * guessed ones, which is the whole point of accepting UBL on the way in.
 */
function ingestUbl(User $owner, string $xml, string $filename = 'invoice.xml'): InvoiceInboxItem
{
    Storage::fake('local');
    Storage::disk('local')->put("incoming/{$filename}", $xml);

    $item = app(ProcessInboxFileActionInterface::class)->execute($owner, 'local', "incoming/{$filename}");

    // Null only means "duplicate hash", which no test here produces —
    // asserting it up front keeps every caller free of null handling.
    expect($item)->not->toBeNull();
    assert($item !== null);

    return $item;
}

function minimalUbl(string $number = 'DODA-1', string $ico = '12345678'): string
{
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
             xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
             xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
      <cbc:ID>{$number}</cbc:ID>
      <cbc:IssueDate>2026-03-01</cbc:IssueDate>
      <cbc:DueDate>2026-03-15</cbc:DueDate>
      <cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
      <cac:AccountingSupplierParty><cac:Party>
        <cac:PartyLegalEntity><cbc:CompanyID>{$ico}</cbc:CompanyID></cac:PartyLegalEntity>
      </cac:Party></cac:AccountingSupplierParty>
      <cac:PaymentMeans>
        <cbc:PaymentID>7788</cbc:PaymentID>
        <cac:PayeeFinancialAccount><cbc:ID>SK3112000000198742637541</cbc:ID></cac:PayeeFinancialAccount>
      </cac:PaymentMeans>
      <cac:TaxTotal>
        <cbc:TaxAmount currencyID="EUR">46.00</cbc:TaxAmount>
        <cac:TaxSubtotal>
          <cbc:TaxableAmount currencyID="EUR">200.00</cbc:TaxableAmount>
          <cbc:TaxAmount currencyID="EUR">46.00</cbc:TaxAmount>
          <cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>23.00</cbc:Percent></cac:TaxCategory>
        </cac:TaxSubtotal>
      </cac:TaxTotal>
      <cac:LegalMonetaryTotal><cbc:TaxInclusiveAmount currencyID="EUR">246.00</cbc:TaxInclusiveAmount></cac:LegalMonetaryTotal>
      <cac:InvoiceLine>
        <cbc:ID>1</cbc:ID>
        <cbc:LineExtensionAmount currencyID="EUR">200.00</cbc:LineExtensionAmount>
        <cac:Item><cbc:Name>Služby</cbc:Name></cac:Item>
      </cac:InvoiceLine>
    </Invoice>
    XML;
}

it('settles an incoming UBL invoice without running OCR at all', function (): void {
    $owner = createUser();

    $item = ingestUbl($owner, minimalUbl());
    $suggestions = $item->suggestions ?? [];

    expect($item->status)->toBe(InvoiceInboxStatus::Pending->value)
        ->and($item->suggestions_source)->toBe('ubl')
        // Nothing was OCR'd, so claiming an engine or storing extracted text
        // would misrepresent where the numbers came from.
        ->and($item->ocr_engine)->toBeNull()
        ->and($item->ocr_text)->toBeNull()
        ->and($suggestions['supplier_invoice_number'] ?? null)->toBe('DODA-1')
        // toEqual, not toBe: the suggestions round-trip through jsonb, and
        // 246.0 comes back as an int.
        ->and($suggestions['total'] ?? null)->toEqual(246.0)
        ->and($suggestions['variable_symbol'] ?? null)->toBe('7788')
        ->and($suggestions['iban'] ?? null)->toBe('SK3112000000198742637541')
        ->and($suggestions['vat_breakdown'][0]['rate'] ?? null)->toEqual(23.0);
});

it('matches the vendor by the registration id stated in the document', function (): void {
    $owner = createUser();
    $vendor = Client::factory()->create([
        'user_id' => $owner->id,
        'is_vendor' => true,
        'ico' => '99887766',
    ]);

    $item = ingestUbl($owner, minimalUbl(ico: '99887766'));

    expect($item->matched_client_id)->toBe($vendor->id);
});

it('still falls back to OCR for an XML attachment that is not an e-invoice', function (): void {
    $owner = createUser();

    // An XML attachment is not automatically a UBL invoice — the inbox has
    // to keep working for whatever else turns up.
    $item = ingestUbl($owner, '<?xml version="1.0"?><somethingElse/>', 'other.xml');

    expect($item->suggestions_source)->not->toBe('ubl');
});
