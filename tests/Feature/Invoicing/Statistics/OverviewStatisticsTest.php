<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\TimeTracking\Domain\Models\ExchangeRate;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/statistics/overview')->assertUnauthorized();
});

it('counts revenue on subtotal for VAT payers', function (): void {
    $user = createUser(['is_vat_payer' => true, 'vat_status' => 'payer', 'default_currency' => 'CZK']);

    Invoice::factory()->create([
        'user_id' => $user->id,
        'type' => 'invoice',
        'status' => 'issued',
        'currency' => 'CZK',
        'issued_at' => '2026-07-10',
        'taxable_supply_at' => null,
        'subtotal' => 1000,
        'vat_amount' => 210,
        'total' => 1210,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk()
        ->assertJsonPath('data.kpi.revenue.this_month.value', 1000);
});

it('counts revenue on total for VAT non-payers', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);

    Invoice::factory()->create([
        'user_id' => $user->id,
        'type' => 'invoice',
        'status' => 'issued',
        'currency' => 'CZK',
        'issued_at' => '2026-07-10',
        'taxable_supply_at' => null,
        'subtotal' => 1000,
        'vat_amount' => 210,
        'total' => 1210,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk()
        ->assertJsonPath('data.kpi.revenue.this_month.value', 1210);
});

it('excludes proformas, drafts and cancelled originals, and nets a storno to zero', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);

    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'proforma', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => '2026-07-10', 'taxable_supply_at' => null, 'total' => 500,
    ]);
    Invoice::factory()->draft()->create([
        'user_id' => $user->id, 'type' => 'invoice',
        'currency' => 'CZK', 'issued_at' => '2026-07-10', 'taxable_supply_at' => null, 'total' => 500,
    ]);

    // Storno flow: original auto-cancelled, storno document itself is not a revenue type.
    $original = Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'cancelled',
        'currency' => 'CZK', 'issued_at' => '2026-07-10', 'taxable_supply_at' => null, 'total' => 1000,
    ]);
    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'storno', 'status' => 'issued', 'related_invoice_id' => $original->id,
        'currency' => 'CZK', 'issued_at' => '2026-07-10', 'taxable_supply_at' => null, 'total' => -1000,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk()
        ->assertJsonPath('data.kpi.revenue.this_month.value', 0);
});

it('nets a credited original against its credit note to zero', function (): void {
    $user = createUser(['is_vat_payer' => true, 'vat_status' => 'payer', 'default_currency' => 'CZK']);

    $original = Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'credited',
        'currency' => 'CZK', 'issued_at' => '2026-07-10', 'taxable_supply_at' => null, 'subtotal' => 1000,
    ]);
    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'credit_note', 'status' => 'issued', 'related_invoice_id' => $original->id,
        'currency' => 'CZK', 'issued_at' => '2026-07-10', 'taxable_supply_at' => null, 'subtotal' => -1000,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk()
        ->assertJsonPath('data.kpi.revenue.this_month.value', 0);
});

it('dates revenue by DUZP, falling back to issued_at when taxable_supply_at is null', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);

    // DUZP in June, issued in July — must land in June.
    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => '2026-07-05', 'taxable_supply_at' => '2026-06-20', 'total' => 400,
    ]);
    // No DUZP — falls back to issued_at (June).
    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => '2026-06-10', 'taxable_supply_at' => null, 'total' => 600,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk();

    /** @var array<int, array<string, mixed>> $trendJson */
    $trendJson = $response->json('data.monthly_trend');
    $trend = collect($trendJson)->keyBy('month');

    expect(($trend['2026-06'] ?? [])['revenue'])->toEqual(1000.0)
        ->and(($trend['2026-07'] ?? [])['revenue'])->toEqual(0.0);
});

it('converts a foreign-currency invoice using its frozen exchange rate snapshot', function (): void {
    $user = createUser(['is_vat_payer' => true, 'vat_status' => 'payer', 'default_currency' => 'CZK']);

    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'EUR', 'exchange_rate_snapshot' => 25.0,
        'issued_at' => '2026-07-10', 'taxable_supply_at' => null, 'subtotal' => 100,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk()
        ->assertJsonPath('data.kpi.revenue.this_month.value', 2500);
});

it('falls back to a stored exchange rate when no snapshot was frozen', function (): void {
    $user = createUser(['is_vat_payer' => true, 'vat_status' => 'payer', 'default_currency' => 'CZK']);

    ExchangeRate::factory()->system()->create([
        'base_currency' => 'EUR',
        'target_currency' => 'CZK',
        'rate' => 24.5,
        'date' => '2026-07-01',
    ]);

    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'EUR', 'exchange_rate_snapshot' => null,
        'issued_at' => '2026-07-10', 'taxable_supply_at' => null, 'subtotal' => 100,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk()
        ->assertJsonPath('data.kpi.revenue.this_month.value', 2450);
});

it('converts totals into a non-CZK default currency', function (): void {
    $user = createUser(['is_vat_payer' => true, 'vat_status' => 'payer', 'default_currency' => 'EUR']);

    ExchangeRate::factory()->system()->create([
        'base_currency' => 'EUR',
        'target_currency' => 'CZK',
        'rate' => 25.0,
        'date' => '2026-07-01',
    ]);

    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'CZK',
        'issued_at' => '2026-07-10', 'taxable_supply_at' => null, 'subtotal' => 2500,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk()
        ->assertJsonPath('data.currency', 'EUR')
        ->assertJsonPath('data.kpi.revenue.this_month.value', 100);
});

it('reports a null trend percentage when last month had no revenue', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);

    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => '2026-07-10', 'taxable_supply_at' => null, 'total' => 500,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk()
        ->assertJsonPath('data.kpi.revenue.trend_vs_last_month_percent', null);
});

it('returns the correct date ranges for every comparison period', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk();

    /** @var array<int, array<string, mixed>> $comparisonJson */
    $comparisonJson = $response->json('data.comparison');
    $rows = collect($comparisonJson)->keyBy('period');

    expect($rows['this_month'])->toMatchArray(['date_from' => '2026-07-01', 'date_to' => '2026-07-15'])
        ->and($rows['last_month'])->toMatchArray(['date_from' => '2026-06-01', 'date_to' => '2026-06-30'])
        ->and($rows['rolling_12m'])->toMatchArray(['date_from' => '2025-08-01', 'date_to' => '2026-07-15'])
        ->and($rows['ytd'])->toMatchArray(['date_from' => '2026-01-01', 'date_to' => '2026-07-15'])
        ->and($rows['last_year'])->toMatchArray(['date_from' => '2025-01-01', 'date_to' => '2025-12-31']);
});
