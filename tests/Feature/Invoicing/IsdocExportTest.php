<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Services\IsdocBuilder;

/**
 * @param  array<string, mixed>  $invoiceAttributes
 * @return array{0: User, 1: Invoice}
 */
function isdocInvoice(array $invoiceAttributes = []): array
{
    $user = createUser([
        'ico' => '12345678', 'dic' => '2020202020', 'vat_id' => 'SK2020202020',
        'is_vat_payer' => true, 'vat_status' => 'payer', 'country' => 'SK',
    ]);

    $client = Client::factory()->create([
        'user_id' => $user->id, 'client_type' => 'company', 'company_name' => 'Klient s.r.o.',
        'country' => 'SK', 'ico' => '87654321',
    ]);

    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'invoice',
        'currency' => 'EUR',
        'variable_symbol' => '2026001',
        'discount_percent' => null,
        'issued_at' => '2026-01-10',
        'due_at' => '2026-01-24',
        'taxable_supply_at' => '2026-01-10',
        'supplier_snapshot' => [
            'name' => $user->full_name, 'ico' => $user->ico, 'dic' => $user->dic,
            'vat_id' => $user->vat_id, 'is_vat_payer' => $user->is_vat_payer,
            'address' => 'Hlavná 1', 'city' => 'Bratislava', 'postal_code' => '81101',
            'country' => 'SK',
        ],
        'client_snapshot' => [
            'name' => 'Klient s.r.o.', 'ico' => '87654321', 'dic' => '3030303030',
            'is_vat_payer' => true, 'vat_id' => 'SK3030303030',
            'address' => 'Nová 5', 'city' => 'Košice', 'postal_code' => '04001',
            'country' => 'SK',
        ],
        'bank_account_snapshot' => [
            'account_number' => '123456789/0900',
            'bank_name' => 'Slovenská sporiteľňa',
            'iban' => 'SK1234567890123456789012',
            'bic' => 'GIBASKBX',
        ],
        ...$invoiceAttributes,
    ]);

    $invoice->items()->create([
        'description' => 'Konzultácia', 'quantity' => 10, 'unit' => 'hod',
        'unit_price' => 50, 'vat_rate' => 23, 'vat_amount' => 115,
        'total_excl_vat' => 500, 'total_incl_vat' => 615, 'sort_order' => 0,
    ]);

    $invoice->refresh()->recalculateTotals()->save();

    return [$user, $invoice->refresh()->load('items')];
}

/**
 * @return array{0: bool, 1: list<LibXMLError>}
 */
function validateIsdocXsd(string $xml): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $valid = $dom->schemaValidate(base_path('tests/Fixtures/isdoc/isdoc-invoice-6.0.2.xsd'));
    $errors = libxml_get_errors();
    libxml_clear_errors();

    return [$valid, $errors];
}

it('produces XSD-valid ISDOC XML for an issued invoice', function (): void {
    [, $invoice] = isdocInvoice();

    $xml = app(IsdocBuilder::class)->build($invoice);

    [$valid, $errors] = validateIsdocXsd($xml);

    expect($errors)->toBeEmpty()
        ->and($valid)->toBeTrue()
        ->and($xml)->toContain('<DocumentType>1</DocumentType>')
        ->and($xml)->toContain('<ID>'.$invoice->invoice_number.'</ID>')
        ->and($xml)->toContain('<LocalCurrencyCode>EUR</LocalCurrencyCode>');
});

it('produces XSD-valid ISDOC XML for a credit note', function (): void {
    [, $invoice] = isdocInvoice(['type' => 'credit_note']);

    $xml = app(IsdocBuilder::class)->build($invoice);

    [$valid, $errors] = validateIsdocXsd($xml);

    expect($errors)->toBeEmpty()
        ->and($valid)->toBeTrue()
        ->and($xml)->toContain('<DocumentType>2</DocumentType>');
});

it('maps supplier and customer party identification from the frozen snapshots', function (): void {
    [, $invoice] = isdocInvoice();

    $xml = app(IsdocBuilder::class)->build($invoice);

    expect($xml)->toContain('<AccountingSupplierParty>')
        ->toContain('<ID>12345678</ID>')
        ->toContain('<CompanyID>SK2020202020</CompanyID>')
        ->toContain('<AccountingCustomerParty>')
        ->toContain('<ID>87654321</ID>')
        ->toContain('<CompanyID>SK3030303030</CompanyID>');
});

it('recaps VAT per rate in TaxTotal matching the invoice totals', function (): void {
    [, $invoice] = isdocInvoice();

    $xml = app(IsdocBuilder::class)->build($invoice);

    expect($xml)->toContain('<TaxableAmount>500.00</TaxableAmount>')
        ->toContain('<TaxInclusiveAmount>615.00</TaxInclusiveAmount>');

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $taxAmountNodes = $dom->getElementsByTagName('TaxAmount');
    // TaxSubTotal's TaxAmount (VAT for the rate) and TaxTotal's own trailing TaxAmount (grand total).
    expect($taxAmountNodes->length)->toBe(2)
        ->and($taxAmountNodes->item(1)?->textContent)->toBe('115.00');
});

it('includes PaymentMeans with IBAN/BIC when the bank snapshot has a slash-formatted account number', function (): void {
    [, $invoice] = isdocInvoice();

    $xml = app(IsdocBuilder::class)->build($invoice);

    expect($xml)->toContain('<PaymentMeans>')
        ->toContain('<IBAN>SK1234567890123456789012</IBAN>')
        ->toContain('<BIC>GIBASKBX</BIC>')
        ->toContain('<VariableSymbol>2026001</VariableSymbol>');
});

it('omits PaymentMeans when there is no usable bank account snapshot', function (): void {
    [, $invoice] = isdocInvoice(['bank_account_snapshot' => null]);

    $xml = app(IsdocBuilder::class)->build($invoice);

    [$valid, $errors] = validateIsdocXsd($xml);

    expect($errors)->toBeEmpty()
        ->and($valid)->toBeTrue()
        ->and($xml)->not->toContain('<PaymentMeans>');
});

it('rejects exporting a draft invoice', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->draft()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->actingAs($user)
        ->get("/api/v1/invoices/{$invoice->id}/export/isdoc")
        ->assertUnprocessable();
});

it('rejects exporting a storno document', function (): void {
    [$user, $invoice] = isdocInvoice(['type' => 'storno']);

    $this->actingAs($user)
        ->get("/api/v1/invoices/{$invoice->id}/export/isdoc")
        ->assertUnprocessable();
});

it('rejects exporting a proforma document', function (): void {
    [$user, $invoice] = isdocInvoice(['type' => 'proforma']);

    $this->actingAs($user)
        ->get("/api/v1/invoices/{$invoice->id}/export/isdoc")
        ->assertUnprocessable();
});

it('downloads a valid ISDOC file over the API with the invoice number as filename', function (): void {
    [$user, $invoice] = isdocInvoice();

    $response = $this->actingAs($user)->get("/api/v1/invoices/{$invoice->id}/export/isdoc");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/xml')
        ->and($response->headers->get('Content-Disposition'))->toContain($invoice->invoice_number.'.isdoc');

    [$valid, $errors] = validateIsdocXsd($response->getContent());
    expect($errors)->toBeEmpty()->and($valid)->toBeTrue();
});

it('does not let a user export another account invoice', function (): void {
    $victim = createUser();
    $victimClient = Client::factory()->create(['user_id' => $victim->id]);
    $victimInvoice = Invoice::factory()->sent()->create(['user_id' => $victim->id, 'client_id' => $victimClient->id]);

    $attacker = createUser();

    $this->actingAs($attacker)
        ->get("/api/v1/invoices/{$victimInvoice->id}/export/isdoc")
        ->assertNotFound();
});
