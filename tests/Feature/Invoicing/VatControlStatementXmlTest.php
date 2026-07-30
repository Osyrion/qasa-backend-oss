<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementReportData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementRowData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementSummaryRowData;
use App\Modules\Taxation\Infrastructure\Cz\DphKh1XmlBuilder;
use App\Modules\Taxation\Infrastructure\Sk\KvDphXmlBuilder;
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
    $this->actingAs($user)->postJson("/api/v1/invoices/{$rc->json('data.id')}/items", [
        'description' => 'Stavební práce', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 5000, 'vat_rate' => 21,
    ])->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$rc->json('data.id')}/status", ['status' => 'sent'])->assertOk();

    // B.1: self-assessed received.
    $euVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'DE', 'dic' => '55667788']);
    $b1 = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($euVendor->id, [
        'issued_at' => '2027-03-08', 'currency' => 'CZK', 'vat_regime' => 'eu_reverse_charge',
        'vat_lines' => [['vat_rate' => 21, 'base' => 20000, 'vat_amount' => 4200]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$b1->json('data.id')}/status", ['status' => 'received'])->assertOk();

    // B.2/B.3: domestic received, above and below threshold.
    $domesticVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'CZ', 'dic' => '99887766']);
    $b2 = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($domesticVendor->id, [
        'issued_at' => '2027-03-09', 'currency' => 'CZK',
        'vat_lines' => [['vat_rate' => 21, 'base' => 12000, 'vat_amount' => 2520]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$b2->json('data.id')}/status", ['status' => 'received'])->assertOk();

    $b3 = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($domesticVendor->id, [
        'issued_at' => '2027-03-10', 'currency' => 'CZK',
        'vat_lines' => [['vat_rate' => 21, 'base' => 200, 'vat_amount' => 42]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$b3->json('data.id')}/status", ['status' => 'received'])->assertOk();

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
    $this->actingAs($user)->postJson("/api/v1/invoices/{$creditNote->json('data.id')}/status", ['status' => 'sent'])->assertOk();

    // A.2: domestic reverse charge issued.
    $rcClient = Client::factory()->create([
        'user_id' => $user->id, 'country' => 'SK', 'reverse_charge_allowed' => true,
        'vat_id' => 'SK3030303030', 'is_vat_payer' => true,
    ]);
    $rc = $this->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $rcClient->id, 'issued_at' => '2027-03-06', 'due_at' => '2027-03-20',
        'currency' => 'EUR', 'reverse_charge' => true,
    ])->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$rc->json('data.id')}/items", [
        'description' => 'Stavebné práce', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 800, 'vat_rate' => 23,
    ])->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$rc->json('data.id')}/status", ['status' => 'sent'])->assertOk();

    // B.1: self-assessed received.
    $euVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'DE', 'vat_id' => 'DE555666777', 'is_vat_payer' => true]);
    $b1 = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($euVendor->id, [
        'issued_at' => '2027-03-07', 'vat_regime' => 'eu_reverse_charge',
        'vat_lines' => [['vat_rate' => 23, 'base' => 1000, 'vat_amount' => 230]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$b1->json('data.id')}/status", ['status' => 'received'])->assertOk();

    // B.2: domestic received with deduction.
    $domesticVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'SK', 'vat_id' => 'SK4040404040', 'is_vat_payer' => true]);
    $b2 = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($domesticVendor->id, [
        'issued_at' => '2027-03-08',
        'vat_lines' => [['vat_rate' => 23, 'base' => 500, 'vat_amount' => 115]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$b2->json('data.id')}/status", ['status' => 'received'])->assertOk();

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

/*
|--------------------------------------------------------------------------
| Phase 0 — golden snapshots (docs/plans/TAX_RESIDENCY_PHASE_0_GOLDEN_SNAPSHOTS.md)
|--------------------------------------------------------------------------
|
| Byte-exact regression guards ahead of the Taxation module refactor: the
| report DTO is built directly (no HTTP round trip) so the fixture is fully
| deterministic and independent of invoice-creation business logic.
*/

it('builds a byte-identical golden SK KVDPH_2025 XML — payer + domestic RC + dobropis', function (): void {
    $user = User::factory()->make([
        'title' => null, 'name' => 'Ján', 'surname' => 'Novák',
        'email' => 'jan.novak@example.sk', 'phone' => '+421900123456',
        'vat_id' => 'SK1234567890', 'country' => 'SK',
        'address' => 'Hlavná 1', 'city' => 'Bratislava', 'postal_code' => '81101',
    ]);

    $report = new VatControlStatementReportData(
        country: 'SK',
        year: 2026,
        month: 3,
        quarter: null,
        rowSections: [
            'A1' => [new VatControlStatementRowData(
                documentNumber: 'FA-2026-1', date: '2026-03-05', partnerName: 'Klient s.r.o.',
                partnerTaxId: 'SK2020202020', rate: 23, base: 1000.0, vat: 230.0,
            )],
            // Domestic reverse charge issued — folds into <A1> too, see KvDphXmlBuilder.
            'A2' => [new VatControlStatementRowData(
                documentNumber: 'FA-2026-2', date: '2026-03-06', partnerName: 'RC Klient s.r.o.',
                partnerTaxId: 'SK3030303030', rate: 23, base: 800.0, vat: 0.0,
            )],
            'B1' => [new VatControlStatementRowData(
                documentNumber: 'FD-2026-1', date: '2026-03-07', partnerName: 'Dodávateľ EU s.r.o.',
                partnerTaxId: 'DE555666777', rate: 23, base: 1000.0, vat: 230.0,
            )],
            'B2' => [new VatControlStatementRowData(
                documentNumber: 'FD-2026-2', date: '2026-03-08', partnerName: 'Dodávateľ SK s.r.o.',
                partnerTaxId: 'SK4040404040', rate: 23, base: 500.0, vat: 115.0,
            )],
            // C.1 — dobropis referencing the original A.1 invoice.
            'C1' => [new VatControlStatementRowData(
                documentNumber: 'DO-2026-1', date: '2026-03-10', partnerName: 'Klient s.r.o.',
                partnerTaxId: 'SK2020202020', rate: 23, base: -100.0, vat: -23.0,
                relatedDocumentNumber: 'FA-2026-1',
            )],
        ],
        summarySections: [],
        assumptions: [],
    );

    $output = app(KvDphXmlBuilder::class)->build($report, $user);

    expect($output)->toBe((string) file_get_contents(base_path('tests/Fixtures/golden/kv_dph.xml')));
});

it('builds a byte-identical golden CZ DPHKH1 XML — payer + domestic RC', function (): void {
    $user = User::factory()->make([
        'title' => null, 'name' => 'Petr', 'surname' => 'Svoboda',
        'email' => 'petr.svoboda@example.cz', 'phone' => null,
        'dic' => '12345678', 'country' => 'CZ',
        'address' => 'Hlavní 1', 'city' => 'Praha', 'postal_code' => '11000',
    ]);

    $report = new VatControlStatementReportData(
        country: 'CZ',
        year: 2026,
        month: 3,
        quarter: null,
        rowSections: [
            // Domestic reverse charge issued.
            'A1' => [new VatControlStatementRowData(
                documentNumber: 'FA-2026-10', date: '2026-03-07', partnerName: 'RC Klient s.r.o.',
                partnerTaxId: '11223344', rate: 21, base: 800.0, vat: 0.0,
            )],
            // Above the 10 000 Kč threshold.
            'A4' => [new VatControlStatementRowData(
                documentNumber: 'FA-2026-11', date: '2026-03-05', partnerName: 'Klient s.r.o.',
                partnerTaxId: '87654321', rate: 21, base: 15000.0, vat: 3150.0,
            )],
            // Self-assessed received.
            'B1' => [new VatControlStatementRowData(
                documentNumber: 'FD-2026-10', date: '2026-03-08', partnerName: 'Dodavatel EU s.r.o.',
                partnerTaxId: '55667788', rate: 21, base: 1000.0, vat: 210.0,
            )],
            // Domestic received, above threshold.
            'B2' => [new VatControlStatementRowData(
                documentNumber: 'FD-2026-11', date: '2026-03-09', partnerName: 'Dodavatel CZ s.r.o.',
                partnerTaxId: '99887766', rate: 21, base: 12000.0, vat: 2520.0,
            )],
        ],
        summarySections: [
            // Below-threshold issued, folded into the cumulative summary.
            'A5' => [new VatControlStatementSummaryRowData(rate: 12, base: 100.0, vat: 12.0)],
            // Below-threshold received, folded into the cumulative summary.
            'B3' => [new VatControlStatementSummaryRowData(rate: 21, base: 200.0, vat: 42.0)],
        ],
        assumptions: [],
    );

    $output = app(DphKh1XmlBuilder::class)->build($report, $user);

    expect($output)->toBe((string) file_get_contents(base_path('tests/Fixtures/golden/dph_kh1.xml')));
});
