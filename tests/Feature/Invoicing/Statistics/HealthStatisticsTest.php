<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/statistics/health')->assertUnauthorized();
});

it('computes DSO from the last of several partial payments, DPO, and the working capital cycle', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer']);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'paid',
        'currency' => 'CZK', 'issued_at' => '2026-06-01', 'due_at' => '2026-06-15',
        'taxable_supply_at' => null, 'total' => 1000,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 300, 'paid_at' => '2026-06-10']);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 700, 'paid_at' => '2026-06-20']);

    // Paid but never actually recorded a payment — must be excluded from the sample.
    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'paid',
        'currency' => 'CZK', 'issued_at' => '2026-06-01', 'due_at' => '2026-06-15',
        'taxable_supply_at' => null, 'total' => 500,
    ]);

    SupplierInvoice::factory()->create([
        'user_id' => $user->id, 'status' => 'paid',
        'currency' => 'CZK', 'issued_at' => '2026-05-01', 'due_at' => '2026-05-15',
        'taxable_supply_at' => null, 'paid_at' => '2026-05-21', 'total' => 400,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/health')
        ->assertOk();

    expect($response->json('data.dso.days'))->toEqual(19.0)
        ->and($response->json('data.dso.sample_size'))->toBe(1)
        ->and($response->json('data.payment_morale.late_percent'))->toEqual(100.0)
        ->and($response->json('data.payment_morale.avg_days_late'))->toEqual(5.0)
        ->and($response->json('data.dpo.days'))->toEqual(20.0)
        ->and($response->json('data.dpo.sample_size'))->toBe(1)
        ->and($response->json('data.working_capital_cycle_days'))->toEqual(-1.0);
});

it('counts a payment made exactly on the due date as on time', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer']);

    $onTime = Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'paid',
        'currency' => 'CZK', 'issued_at' => '2026-06-01', 'due_at' => '2026-06-15',
        'taxable_supply_at' => null, 'total' => 500,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $onTime->id, 'amount' => 500, 'paid_at' => '2026-06-15']);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/health')
        ->assertOk();

    expect($response->json('data.payment_morale.on_time_percent'))->toEqual(100.0)
        ->and($response->json('data.payment_morale.late_percent'))->toEqual(0.0);
});

it('reports null health metrics when there is no paid sample', function (): void {
    $user = createUser();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/health')
        ->assertOk();

    expect($response->json('data.dso.days'))->toBeNull()
        ->and($response->json('data.dpo.days'))->toBeNull()
        ->and($response->json('data.working_capital_cycle_days'))->toBeNull()
        ->and($response->json('data.client_concentration.top1_share_percent'))->toBeNull();
});

it('classifies client concentration risk at the documented thresholds', function (array $shares, string $expected): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);
    $total = 1000;

    foreach ($shares as $sharePercent) {
        $client = Client::factory()->create(['user_id' => $user->id]);
        Invoice::factory()->create([
            'user_id' => $user->id, 'client_id' => $client->id, 'type' => 'invoice', 'status' => 'issued',
            'currency' => 'CZK', 'issued_at' => '2026-07-01', 'taxable_supply_at' => null,
            'total' => $total * $sharePercent / 100,
        ]);
    }

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/health')
        ->assertOk();

    expect($response->json('data.client_concentration.risk_level'))->toBe($expected);
})->with([
    // First share is the top client; the rest are split into pieces small
    // enough that none of them overtakes it as the actual top1.
    'low at 24.9%' => [[24.9, 18.775, 18.775, 18.775, 18.775], 'low'],
    'medium at 30%' => [[30, 23.34, 23.33, 23.33], 'medium'],
    'high at 41%' => [[41, 29.5, 29.5], 'high'],
]);

it('computes the smallest pareto client count reaching 80% of revenue', function (): void {
    $user = createUser(['is_vat_payer' => false, 'vat_status' => 'non_payer', 'default_currency' => 'CZK']);

    foreach ([500, 300, 200] as $amount) {
        $client = Client::factory()->create(['user_id' => $user->id]);
        Invoice::factory()->create([
            'user_id' => $user->id, 'client_id' => $client->id, 'type' => 'invoice', 'status' => 'issued',
            'currency' => 'CZK', 'issued_at' => '2026-07-01', 'taxable_supply_at' => null, 'total' => $amount,
        ]);
    }

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/health')
        ->assertOk();

    expect($response->json('data.client_concentration.pareto_count'))->toBe(2);
});
