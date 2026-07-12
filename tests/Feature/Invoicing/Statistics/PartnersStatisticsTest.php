<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/statistics/partners')->assertUnauthorized();
});

it('ranks the single client in a currency at 100% share', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer']);
    $client = Client::factory()->create(['user_id' => $user->id, 'is_customer' => true]);

    Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => '2026-07-01', 'taxable_supply_at' => null, 'total' => 1000,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/partners')
        ->assertOk();

    $top = $response->json('data.top_clients.CZK');

    expect($top)->toHaveCount(1)
        ->and($top[0]['client_id'])->toBe($client->id)
        ->and($top[0]['percent_share'])->toEqual(100.0);
});

it('excludes revenue older than the rolling 12-month window', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer']);
    $client = Client::factory()->create(['user_id' => $user->id, 'is_customer' => true]);

    // 13 months before 2026-07-15 — outside rolling12 (from 2025-08-01).
    Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => '2025-06-10', 'taxable_supply_at' => null, 'total' => 1000,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/partners')
        ->assertOk();

    expect($response->json('data.top_clients'))->toBe([]);
});

it('respects the limit parameter per currency', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer']);

    foreach ([300, 200, 100] as $amount) {
        $client = Client::factory()->create(['user_id' => $user->id, 'is_customer' => true]);
        Invoice::factory()->create([
            'user_id' => $user->id, 'client_id' => $client->id, 'type' => 'invoice', 'status' => 'issued',
            'currency' => 'CZK', 'issued_at' => '2026-07-01', 'taxable_supply_at' => null, 'total' => $amount,
        ]);
    }

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/partners?limit=2')
        ->assertOk();

    $top = $response->json('data.top_clients.CZK');

    expect($top)->toHaveCount(2)
        ->and($top[0]['amount'])->toEqual(300.0)
        ->and($top[1]['amount'])->toEqual(200.0);
});

it('excludes a client with only proforma invoices from top clients', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer']);
    $client = Client::factory()->create(['user_id' => $user->id, 'is_customer' => true]);

    Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id, 'type' => 'proforma', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => '2026-07-01', 'taxable_supply_at' => null, 'total' => 1000,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/partners')
        ->assertOk();

    expect($response->json('data.top_clients'))->toBe([]);
});

it('flags churn risk only past the 60-day threshold, with correct days-since and converted lifetime revenue', function (): void {
    $user = createUser(['is_vat_payer' => true, 'vat_status' => 'payer', 'default_currency' => 'CZK']);
    $safe = Client::factory()->create(['user_id' => $user->id, 'is_customer' => true]);
    $churned = Client::factory()->create(['user_id' => $user->id, 'is_customer' => true]);

    // 59 days ago — inside the threshold, not at risk.
    Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $safe->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => now()->subDays(59)->toDateString(), 'taxable_supply_at' => null, 'subtotal' => 300,
    ]);

    // 61 days ago — past the threshold, at risk.
    Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $churned->id, 'type' => 'invoice', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => now()->subDays(61)->toDateString(), 'taxable_supply_at' => null, 'subtotal' => 500,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/partners')
        ->assertOk();

    /** @var array<int, array<string, mixed>> $churnJson */
    $churnJson = $response->json('data.churn_risk');
    $churn = collect($churnJson)->keyBy('client_id');

    $churnedRow = $churn[$churned->id] ?? [];

    expect($churn->has($safe->id))->toBeFalse()
        ->and($churn->has($churned->id))->toBeTrue()
        ->and($churnedRow['days_since_last_invoice'])->toBe(61)
        ->and($churnedRow['lifetime_revenue'])->toEqual(500.0)
        ->and($churnedRow['currency'])->toBe('CZK');
});
