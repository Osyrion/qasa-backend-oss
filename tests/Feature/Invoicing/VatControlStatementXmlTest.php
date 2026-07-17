<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use Illuminate\Support\Carbon;

// Corrective documents (CreateCorrectiveInvoiceAction) are always dated
// "today" — freezing time keeps every fixture in this file inside a single
// reporting period so a full-coverage scenario can be validated in one go.
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{0: bool, 1: list<string>}
 */
function vcsValidateXsd(string $xml, string $xsdPath): array
{
    libxml_use_internal_errors(true);

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $valid = $dom->schemaValidate($xsdPath);

    $errors = array_map(static fn ($e): string => trim((string) $e->message), libxml_get_errors());
    libxml_clear_errors();

    return [$valid, $errors];
}

it('generates a schema-valid CZ DPHKH1 XML draft covering A.1/A.4/A.5/B.1/B.2/B.3', function (): void {
    [$user, $client] = vcsScope('CZ');
    $user->update(['dic' => '12345678']);
    $client->update(['dic' => '87654321', 'is_vat_payer' => true]);

    // A.4: above the 10 000 Kč threshold.
    vcsIssueInvoice($this, $user, $client, '2027-03-05', 15000, 21);
    // A.5: below threshold, folded into the cumulative summary.
    vcsIssueInvoice($this, $user, $client, '2027-03-06', 100, 12);

    // A.1: domestic reverse charge issued.
    $rcClient = Client::factory()->create([
        'user_id' => $user->id, 'country' => 'CZ', 'reverse_charge_allowed' => true,
        'dic' => '11223344', 'is_vat_payer' => true,
    ]);
    $rc = $this->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $rcClient->id, 'issued_at' => '2027-03-07', 'due_at' => '2027-03-21',
        'currency' => 'CZK', 'reverse_charge' => true,
    ])->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$rc->json('id')}/items", [
        'description' => 'Stavební práce', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 5000, 'vat_rate' => 21,
    ])->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$rc->json('id')}/status", ['status' => 'sent'])->assertOk();

    // B.1: self-assessed received.
    $euVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'DE', 'dic' => '55667788']);
    $b1 = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($euVendor->id, [
        'issued_at' => '2027-03-08', 'currency' => 'CZK', 'vat_regime' => 'eu_reverse_charge',
        'vat_lines' => [['vat_rate' => 21, 'base' => 20000, 'vat_amount' => 4200]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$b1->json('id')}/status", ['status' => 'received'])->assertOk();

    // B.2/B.3: domestic received, above and below threshold.
    $domesticVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'CZ', 'dic' => '99887766']);
    $b2 = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($domesticVendor->id, [
        'issued_at' => '2027-03-09', 'currency' => 'CZK',
        'vat_lines' => [['vat_rate' => 21, 'base' => 12000, 'vat_amount' => 2520]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$b2->json('id')}/status", ['status' => 'received'])->assertOk();

    $b3 = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($domesticVendor->id, [
        'issued_at' => '2027-03-10', 'currency' => 'CZK',
        'vat_lines' => [['vat_rate' => 21, 'base' => 200, 'vat_amount' => 42]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$b3->json('id')}/status", ['status' => 'received'])->assertOk();

    $response = $this->actingAs($user)->get('/api/v1/reports/vat-control-statement/xml?'.http_build_query([
        'country' => 'CZ', 'year' => 2027, 'month' => 3,
    ]))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/xml');

    $xml = (string) $response->getContent();

    expect($xml)->toContain('<VetaA1')
        ->toContain('<VetaA4')
        ->toContain('<VetaA5')
        ->toContain('<VetaB1')
        ->toContain('<VetaB2')
        ->toContain('<VetaB3');

    [$valid, $errors] = vcsValidateXsd($xml, base_path('tests/Fixtures/vat-control-statement/dphkh1_epo2.xsd'));

    expect($errors)->toBeEmpty();
    expect($valid)->toBeTrue();
});

it('generates a schema-valid SK KVDPH_2025 XML draft covering A.1 (incl. domestic RC)/B.1/B.2/C.1', function (): void {
    [$user, $client] = vcsScope('SK');
    $user->update(['vat_id' => 'SK1234567890']);
    $client->update(['vat_id' => 'SK2020202020', 'is_vat_payer' => true]);

    // A.1 + the original invoice for C.1's correction.
    $original = vcsIssueInvoice($this, $user, $client, '2027-03-05', 1000, 23);

    $creditNote = $this->actingAs($user)->postJson("/api/v1/invoices/{$original->id}/corrective", [
        'type' => 'credit_note',
    ])->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$creditNote->json('id')}/status", ['status' => 'sent'])->assertOk();

    // A.2: domestic reverse charge issued.
    $rcClient = Client::factory()->create([
        'user_id' => $user->id, 'country' => 'SK', 'reverse_charge_allowed' => true,
        'vat_id' => 'SK3030303030', 'is_vat_payer' => true,
    ]);
    $rc = $this->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $rcClient->id, 'issued_at' => '2027-03-06', 'due_at' => '2027-03-20',
        'currency' => 'EUR', 'reverse_charge' => true,
    ])->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$rc->json('id')}/items", [
        'description' => 'Stavebné práce', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 800, 'vat_rate' => 23,
    ])->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$rc->json('id')}/status", ['status' => 'sent'])->assertOk();

    // B.1: self-assessed received.
    $euVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'DE', 'vat_id' => 'DE555666777', 'is_vat_payer' => true]);
    $b1 = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($euVendor->id, [
        'issued_at' => '2027-03-07', 'vat_regime' => 'eu_reverse_charge',
        'vat_lines' => [['vat_rate' => 23, 'base' => 1000, 'vat_amount' => 230]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$b1->json('id')}/status", ['status' => 'received'])->assertOk();

    // B.2: domestic received with deduction.
    $domesticVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'SK', 'vat_id' => 'SK4040404040', 'is_vat_payer' => true]);
    $b2 = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($domesticVendor->id, [
        'issued_at' => '2027-03-08',
        'vat_lines' => [['vat_rate' => 23, 'base' => 500, 'vat_amount' => 115]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$b2->json('id')}/status", ['status' => 'received'])->assertOk();

    $response = $this->actingAs($user)->get('/api/v1/reports/vat-control-statement/xml?'.http_build_query([
        'country' => 'SK', 'year' => 2027, 'month' => 3,
    ]))->assertOk();

    $xml = (string) $response->getContent();

    // A.2 (domestic RC issued) folds into <A1> too — see KvDphXmlBuilder — so
    // two <A1> rows are expected: the regular invoice and the RC one.
    expect(substr_count($xml, '<A1 '))->toBe(2);
    expect($xml)->toContain('<B1 ')
        ->toContain('<B2 ')
        ->toContain('<C1 ')
        ->toContain('KVDPH_2025');

    [$valid, $errors] = vcsValidateXsd($xml, base_path('tests/Fixtures/vat-control-statement/kv_dph_2025.xsd'));

    expect($errors)->toBeEmpty();
    expect($valid)->toBeTrue();
});

it('rejects an annual-scope SK XML export — KV DPH requires a month or quarter', function (): void {
    [$user] = vcsScope('SK');

    $this->actingAs($user)->get('/api/v1/reports/vat-control-statement/xml?'.http_build_query([
        'country' => 'SK', 'year' => 2027,
    ]))->assertStatus(422);
});

it('rejects the XML draft for a non-payer', function (): void {
    [$user] = vcsScope('SK', 'non_payer');

    $this->actingAs($user)->get('/api/v1/reports/vat-control-statement/xml?'.http_build_query([
        'country' => 'SK', 'year' => 2027, 'month' => 3,
    ]))->assertStatus(422);
});
