<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('includes expenses in monthly costs alongside supplier invoices', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);

    SupplierInvoice::factory()->create([
        'user_id' => $user->id, 'status' => 'received',
        'currency' => 'CZK', 'issued_at' => '2026-07-05', 'taxable_supply_at' => null, 'total' => 300,
    ]);
    Expense::factory()->create([
        'user_id' => $user->id, 'currency' => 'CZK', 'date' => '2026-07-10', 'amount' => 150,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk()
        ->assertJsonPath('data.kpi.costs.this_month.value', 450);
});

it('converts a foreign-currency expense using the stored exchange rate fallback', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);

    ExchangeRate::factory()->system()->create([
        'base_currency' => 'EUR',
        'target_currency' => 'CZK',
        'rate' => 25.0,
        'date' => '2026-07-01',
    ]);

    Expense::factory()->create([
        'user_id' => $user->id, 'currency' => 'EUR', 'date' => '2026-07-10', 'amount' => 100,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk()
        ->assertJsonPath('data.kpi.costs.this_month.value', 2500);
});

it('excludes a soft-deleted expense from costs', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);

    $expense = Expense::factory()->create([
        'user_id' => $user->id, 'currency' => 'CZK', 'date' => '2026-07-10', 'amount' => 150,
    ]);
    $expense->delete();

    $this->actingAs($user)
        ->getJson('/api/v1/statistics/overview')
        ->assertOk()
        ->assertJsonPath('data.kpi.costs.this_month.value', 0);
});

it('lists a year with only expense activity in by_year', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);

    Expense::factory()->create([
        'user_id' => $user->id, 'currency' => 'CZK', 'date' => '2023-05-10', 'amount' => 200,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/tables')
        ->assertOk();

    /** @var array<int, array<string, mixed>> $byYearJson */
    $byYearJson = $response->json('data.by_year');
    $byYear = collect($byYearJson)->keyBy('year');

    $year2023 = $byYear[2023] ?? [];

    expect($byYear->keys()->all())->toContain(2023)
        ->and($year2023['costs'])->toEqual(200.0);
});

it('includes an assumptions disclaimer in the overview and tables responses', function (): void {
    $user = createUser();

    $overview = $this->actingAs($user)->getJson('/api/v1/statistics/overview')->assertOk();
    $tables = $this->actingAs($user)->getJson('/api/v1/statistics/tables')->assertOk();

    expect($overview->json('data.assumptions'))->toBeArray()->not->toBeEmpty()
        ->and($tables->json('data.assumptions'))->toBeArray()->not->toBeEmpty();
});
