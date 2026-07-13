<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @return array{0: User, 1: Client}
 */
function vcsScope(string $country, string $vatStatus = 'payer'): array
{
    $user = createUser(['country' => $country, 'vat_status' => $vatStatus]);
    app(VatRateSeederService::class)->seedFor($user);
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => $country]);

    return [$user, $client];
}

/**
 * @param  array<string, mixed>  $query
 * @return TestResponse<Response>
 */
function vcsRequest(object $test, User $user, array $query): TestResponse
{
    return $test->actingAs($user)->getJson('/api/v1/reports/vat-control-statement?'.http_build_query($query));
}

function vcsIssueInvoice(object $test, User $user, Client $client, string $issuedAt, float $unitPrice, float $vatRate): Invoice
{
    $currency = $client->country === 'CZ' ? 'CZK' : 'EUR';

    $created = $test->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => $issuedAt,
        'due_at' => Carbon::parse($issuedAt)->addDays(14)->toDateString(),
        'currency' => $currency,
    ])->assertCreated();

    $test->actingAs($user)->postJson("/api/v1/invoices/{$created->json('id')}/items", [
        'description' => 'Služby', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => $unitPrice, 'vat_rate' => $vatRate,
    ])->assertCreated();

    $test->actingAs($user)->postJson("/api/v1/invoices/{$created->json('id')}/status", ['status' => 'sent'])
        ->assertOk();

    return Invoice::withoutGlobalScope('user')->whereKey($created->json('id'))->firstOrFail();
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<int, mixed>
 */
function vcsRowsInSection(TestResponse $response, string $section): array
{
    return (array) $response->json("sections.{$section}") ?: [];
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<int, mixed>
 */
function vcsSummaryRowsInSection(TestResponse $response, string $section): array
{
    return (array) $response->json("summary_sections.{$section}") ?: [];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function vcsSupplierInvoicePayload(string $clientId, array $overrides = []): array
{
    return array_merge([
        'client_id' => $clientId,
        'supplier_invoice_number' => 'INV-'.fake()->unique()->numberBetween(1, 99999),
        'issued_at' => now()->toDateString(),
        'currency' => 'EUR',
        'vat_lines' => [
            ['vat_rate' => 23, 'base' => 100, 'vat_amount' => 23],
        ],
    ], $overrides);
}

// ── SK: issued domestic invoices → A.1 ──────────────────────────────────────

it('classifies SK domestic issued invoices into A.1 with document, partner and rate detail', function (): void {
    [$user, $client] = vcsScope('SK');
    $client->update(['vat_id' => 'SK2020202020', 'dic' => '2020202020', 'is_vat_payer' => true]);

    $invoice = vcsIssueInvoice($this, $user, $client, '2026-03-10', 1000, 23);

    $response = vcsRequest($this, $user, ['country' => 'SK', 'year' => 2026, 'month' => 3])->assertOk();

    $rows = vcsRowsInSection($response, 'A1');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['document_number'])->toBe($invoice->invoice_number)
        ->and($rows[0]['partner_tax_id'])->toBe('SK2020202020')
        ->and((float) $rows[0]['rate'])->toBe(23.0)
        ->and((float) $rows[0]['base'])->toBe(1000.0)
        ->and((float) $rows[0]['vat'])->toBe(230.0);

    expect(vcsRowsInSection($response, 'A2'))->toBeEmpty();
});

it('uses the frozen client vat_id snapshot for A.1, unaffected by a later client change', function (): void {
    [$user, $client] = vcsScope('SK');
    $client->update(['vat_id' => 'SK1111111111', 'is_vat_payer' => true]);

    vcsIssueInvoice($this, $user, $client, '2026-04-10', 500, 23);
    $client->update(['vat_id' => 'SK9999999999']);

    $response = vcsRequest($this, $user, ['country' => 'SK', 'year' => 2026, 'month' => 4])->assertOk();

    expect(vcsRowsInSection($response, 'A1')[0]['partner_tax_id'])->toBe('SK1111111111');
});

// ── SK: domestic reverse charge issued → A.2 ────────────────────────────────

it('classifies SK domestic reverse charge issued invoices into A.2 with a zero-rated base', function (): void {
    [$user] = vcsScope('SK');
    $client = Client::factory()->create([
        'user_id' => $user->id, 'country' => 'SK', 'reverse_charge_allowed' => true, 'vat_id' => 'SK2020202020',
    ]);

    $created = $this->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => '2026-05-10',
        'due_at' => '2026-05-24',
        'currency' => 'EUR',
        'reverse_charge' => true,
    ])->assertCreated();

    $this->actingAs($user)->postJson("/api/v1/invoices/{$created->json('id')}/items", [
        'description' => 'Stavebné práce', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 1000, 'vat_rate' => 23,
    ])->assertCreated();

    $this->actingAs($user)->postJson("/api/v1/invoices/{$created->json('id')}/status", ['status' => 'sent'])->assertOk();

    $response = vcsRequest($this, $user, ['country' => 'SK', 'year' => 2026, 'month' => 5])->assertOk();

    $rows = vcsRowsInSection($response, 'A2');

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]['base'])->toBe(1000.0)
        ->and((float) $rows[0]['vat'])->toBe(0.0);

    expect(vcsRowsInSection($response, 'A1'))->toBeEmpty();
});

// ── SK: credit notes issued → C.1 ───────────────────────────────────────────

it('classifies SK issued credit notes into C.1, referencing the original invoice number', function (): void {
    [$user, $client] = vcsScope('SK');

    // Corrective documents are always dated "today" (CreateCorrectiveInvoiceAction),
    // so both the original and the report query must fall in the current month.
    $original = vcsIssueInvoice($this, $user, $client, now()->toDateString(), 1000, 23);

    $creditNote = $this->actingAs($user)->postJson("/api/v1/invoices/{$original->id}/corrective", [
        'type' => 'credit_note',
    ])->assertCreated();

    $issued = $this->actingAs($user)->postJson("/api/v1/invoices/{$creditNote->json('id')}/status", ['status' => 'sent'])
        ->assertOk();

    $response = vcsRequest($this, $user, ['country' => 'SK', 'year' => now()->year, 'month' => now()->month])->assertOk();

    $rows = vcsRowsInSection($response, 'C1');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['document_number'])->toBe($issued->json('invoice_number'))
        ->and($rows[0]['related_document_number'])->toBe($original->invoice_number)
        ->and((float) $rows[0]['base'])->toBeLessThan(0.0);
});

// ── SK: received supplier invoices → B.1 (samozdanenie) / B.2 ──────────────

it('classifies SK self-assessed received invoices into B.1', function (): void {
    $user = createUser(['country' => 'SK', 'vat_status' => 'payer']);
    app(VatRateSeederService::class)->seedFor($user);
    $vendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'DE']);

    $created = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', vcsSupplierInvoicePayload($vendor->id, [
        'issued_at' => '2026-07-05',
        'vat_regime' => 'eu_reverse_charge',
        'vat_lines' => [['vat_rate' => 23, 'base' => 1000, 'vat_amount' => 230]],
    ]))->assertCreated();

    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$created->json('id')}/status", ['status' => 'received'])
        ->assertOk();

    $response = vcsRequest($this, $user, ['country' => 'SK', 'year' => 2026, 'month' => 7])->assertOk();

    $rows = vcsRowsInSection($response, 'B1');

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]['base'])->toBe(1000.0)
        ->and((float) $rows[0]['vat'])->toBe(230.0);

    expect(vcsRowsInSection($response, 'B2'))->toBeEmpty();
});

it('classifies SK domestic received invoices with deduction into B.2', function (): void {
    $user = createUser(['country' => 'SK', 'vat_status' => 'payer']);
    app(VatRateSeederService::class)->seedFor($user);
    $vendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'SK']);

    $created = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', vcsSupplierInvoicePayload($vendor->id, [
        'issued_at' => '2026-08-05',
        'vat_lines' => [['vat_rate' => 23, 'base' => 500, 'vat_amount' => 115]],
    ]))->assertCreated();

    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$created->json('id')}/status", ['status' => 'received'])
        ->assertOk();

    $response = vcsRequest($this, $user, ['country' => 'SK', 'year' => 2026, 'month' => 8])->assertOk();

    $rows = vcsRowsInSection($response, 'B2');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['document_number'])->toBe($created->json('supplier_invoice_number'))
        ->and((float) $rows[0]['base'])->toBe(500.0)
        ->and((float) $rows[0]['vat'])->toBe(115.0);
});

it('leaves SK B.3 and C.2 always empty (not modeled)', function (): void {
    [$user] = vcsScope('SK');

    $response = vcsRequest($this, $user, ['country' => 'SK', 'year' => 2026])->assertOk();

    expect(vcsSummaryRowsInSection($response, 'B3'))->toBeEmpty()
        ->and(vcsRowsInSection($response, 'C2'))->toBeEmpty()
        ->and($response->json('assumptions'))->toContain(
            'B.3 (zjednodušené doklady) a C.2 (dobropisy prijaté) sú vždy prázdne — zjednodušené doklady a dobropisy k prijatým faktúram tento systém zatiaľ neeviduje.'
        );
});

// ── CZ: threshold split A.4/A.5 and B.2/B.3 ─────────────────────────────────

it('splits CZ issued invoices by the 10 000 Kč threshold into A.4 (per doklad) and A.5 (kumulatívne)', function (): void {
    [$user, $client] = vcsScope('CZ');

    $above = vcsIssueInvoice($this, $user, $client, '2026-09-05', 15000, 21);
    vcsIssueInvoice($this, $user, $client, '2026-09-06', 100, 21);

    $response = vcsRequest($this, $user, ['country' => 'CZ', 'year' => 2026, 'month' => 9])->assertOk();

    $a4 = vcsRowsInSection($response, 'A4');
    $a5 = vcsSummaryRowsInSection($response, 'A5');

    expect($a4)->toHaveCount(1)
        ->and($a4[0]['document_number'])->toBe($above->invoice_number)
        ->and((float) $a4[0]['base'])->toBe(15000.0);

    expect($a5)->toHaveCount(1)
        ->and((float) $a5[0]['base'])->toBe(100.0)
        ->and((float) $a5[0]['vat'])->toBe(21.0);
});

it('splits CZ received invoices by the 10 000 Kč threshold into B.2 (per doklad) and B.3 (kumulatívne)', function (): void {
    $user = createUser(['country' => 'CZ', 'vat_status' => 'payer']);
    app(VatRateSeederService::class)->seedFor($user);
    $vendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'CZ']);

    $above = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', vcsSupplierInvoicePayload($vendor->id, [
        'issued_at' => '2026-10-05', 'currency' => 'CZK',
        'vat_lines' => [['vat_rate' => 21, 'base' => 12000, 'vat_amount' => 2520]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$above->json('id')}/status", ['status' => 'received'])->assertOk();

    $below = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', vcsSupplierInvoicePayload($vendor->id, [
        'issued_at' => '2026-10-06', 'currency' => 'CZK',
        'vat_lines' => [['vat_rate' => 21, 'base' => 200, 'vat_amount' => 42]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$below->json('id')}/status", ['status' => 'received'])->assertOk();

    $response = vcsRequest($this, $user, ['country' => 'CZ', 'year' => 2026, 'month' => 10])->assertOk();

    $b2 = vcsRowsInSection($response, 'B2');
    $b3 = vcsSummaryRowsInSection($response, 'B3');

    expect($b2)->toHaveCount(1)
        ->and((float) $b2[0]['base'])->toBe(12000.0);

    expect($b3)->toHaveCount(1)
        ->and((float) $b3[0]['base'])->toBe(200.0);
});

it('classifies CZ self-assessed received invoices into B.1 regardless of amount', function (): void {
    $user = createUser(['country' => 'CZ', 'vat_status' => 'payer']);
    app(VatRateSeederService::class)->seedFor($user);
    $vendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'DE']);

    $created = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', vcsSupplierInvoicePayload($vendor->id, [
        'issued_at' => '2026-11-05', 'currency' => 'CZK',
        'vat_regime' => 'eu_reverse_charge',
        'vat_lines' => [['vat_rate' => 21, 'base' => 20000, 'vat_amount' => 4200]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$created->json('id')}/status", ['status' => 'received'])->assertOk();

    $response = vcsRequest($this, $user, ['country' => 'CZ', 'year' => 2026, 'month' => 11])->assertOk();

    expect(vcsRowsInSection($response, 'B1'))->toHaveCount(1)
        ->and(vcsRowsInSection($response, 'B2'))->toBeEmpty()
        ->and(vcsSummaryRowsInSection($response, 'B3'))->toBeEmpty();
});

// ── EU reverse charge exclusion ──────────────────────────────────────────────

it('excludes EU reverse-charged issued invoices from the control statement', function (): void {
    Http::fake(['ec.europa.eu/*' => Http::response(['isValid' => true, 'name' => 'DE Firma', 'address' => 'Berlin'])]);

    $user = createUser(['country' => 'SK', 'vat_status' => 'payer']);
    app(VatRateSeederService::class)->seedFor($user);
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => 'DE', 'vat_id' => 'DE123456789']);

    $created = $this->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id, 'issued_at' => '2026-12-01', 'due_at' => '2026-12-15', 'currency' => 'EUR',
    ])->assertCreated();

    $this->actingAs($user)->postJson("/api/v1/invoices/{$created->json('id')}/items", [
        'description' => 'Služby', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 1000, 'vat_rate' => 0,
    ])->assertCreated();

    $this->actingAs($user)->postJson("/api/v1/invoices/{$created->json('id')}/status", ['status' => 'sent'])->assertOk();

    $response = vcsRequest($this, $user, ['country' => 'SK', 'year' => 2026, 'month' => 12])->assertOk();

    foreach ($response->json('sections') as $rows) {
        expect($rows)->toBeEmpty();
    }

    expect($response->json('assumptions'))->toContain(
        'Faktúry s prenesením daňovej povinnosti v rámci EÚ nie sú súčasťou tejto zostavy — patria do súhrnného výkazu (EU sales list).'
    );
});

// ── Guards ───────────────────────────────────────────────────────────────────

it('rejects the control statement for a non-payer', function (): void {
    [$user] = vcsScope('SK', 'non_payer');

    vcsRequest($this, $user, ['country' => 'SK', 'year' => 2026])->assertStatus(422);
});

it('rejects an unsupported country', function (): void {
    [$user] = vcsScope('SK');

    vcsRequest($this, $user, ['country' => 'DE', 'year' => 2026])->assertStatus(422);
});
