<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/statistics/tables')->assertUnauthorized();
});

it('filters by_month by the year query parameter', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);

    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => '2025-03-10', 'taxable_supply_at' => null, 'total' => 400,
    ]);
    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => '2026-03-10', 'taxable_supply_at' => null, 'total' => 900,
    ]);

    $response2025 = $this->actingAs($user)
        ->getJson('/api/v1/statistics/tables?year=2025')
        ->assertOk();

    /** @var array<int, array<string, mixed>> $byMonth2025Json */
    $byMonth2025Json = $response2025->json('data.by_month');
    $byMonth2025 = collect($byMonth2025Json)->keyBy('month');
    expect(($byMonth2025['2025-03'] ?? [])['revenue'])->toEqual(400.0)
        ->and($byMonth2025)->toHaveCount(12);

    $responseDefault = $this->actingAs($user)
        ->getJson('/api/v1/statistics/tables')
        ->assertOk();

    /** @var array<int, array<string, mixed>> $byMonthDefaultJson */
    $byMonthDefaultJson = $responseDefault->json('data.by_month');
    $byMonthDefault = collect($byMonthDefaultJson)->keyBy('month');
    expect(($byMonthDefault['2026-03'] ?? [])['revenue'])->toEqual(900.0);
});

it('rejects a year outside the allowed range', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->getJson('/api/v1/statistics/tables?year=1999')
        ->assertStatus(422);
});

it('lists by_year exactly for years with revenue or cost activity', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);

    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => '2026-01-10', 'taxable_supply_at' => null, 'total' => 1000,
    ]);
    // A year with only supplier (cost) activity must still show up.
    SupplierInvoice::factory()->create([
        'user_id' => $user->id, 'status' => 'received',
        'currency' => 'CZK', 'issued_at' => '2024-05-10', 'taxable_supply_at' => null, 'total' => 300,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/tables')
        ->assertOk();

    /** @var array<int, array<string, mixed>> $byYearJson */
    $byYearJson = $response->json('data.by_year');
    $byYear = collect($byYearJson)->keyBy('year');

    $year2026 = $byYear[2026] ?? [];
    $year2024 = $byYear[2024] ?? [];

    expect($byYear->keys()->sort()->values()->all())->toBe([2024, 2026])
        ->and($year2026['revenue'])->toEqual(1000.0)
        ->and($year2024['costs'])->toEqual(300.0)
        ->and($year2026['date_from'])->toBe('2026-01-01')
        ->and($year2026['date_to'])->toBe('2026-12-31');
});
