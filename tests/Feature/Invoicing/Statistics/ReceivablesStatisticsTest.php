<?php

declare(strict_types=1);

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
    $this->getJson('/api/v1/statistics/receivables')->assertUnauthorized();
});

it('buckets receivables across the due-date boundary matrix', function (): void {
    $user = createUser();
    $today = Carbon::now()->startOfDay();

    $cases = [
        'not_yet_due' => $today->copy(),
        'd1_30_low' => $today->copy()->subDays(1),
        'd1_30_high' => $today->copy()->subDays(30),
        'd31_60_low' => $today->copy()->subDays(31),
        'd31_60_high' => $today->copy()->subDays(60),
        'd61_90_low' => $today->copy()->subDays(61),
        'd61_90_high' => $today->copy()->subDays(90),
        'd90_plus' => $today->copy()->subDays(91),
    ];

    foreach ($cases as $dueAt) {
        Invoice::factory()->create([
            'user_id' => $user->id,
            'type' => 'invoice',
            'status' => 'sent',
            'currency' => 'CZK',
            'issued_at' => $dueAt->copy()->subDays(14)->toDateString(),
            'due_at' => $dueAt->toDateString(),
            'total' => 100,
        ]);
    }

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/receivables')
        ->assertOk();

    $czk = $response->json('data.receivables.CZK');

    expect($czk['not_yet_due']['count'])->toBe(1)
        ->and($czk['d1_30']['count'])->toBe(2)
        ->and($czk['d31_60']['count'])->toBe(2)
        ->and($czk['d61_90']['count'])->toBe(2)
        ->and($czk['d90_plus']['count'])->toBe(1);
});

it('reduces the receivable balance by recorded partial payments', function (): void {
    $user = createUser();

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'sent',
        'currency' => 'CZK', 'issued_at' => '2026-06-01', 'due_at' => '2026-06-15', 'total' => 1000,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 300, 'paid_at' => '2026-06-20']);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/receivables')
        ->assertOk();

    expect($response->json('data.receivables.CZK.d1_30.amount'))->toEqual(700.0);
});

it('excludes paid, cancelled and credit-note invoices from receivables', function (): void {
    $user = createUser();

    foreach (['paid', 'cancelled'] as $status) {
        Invoice::factory()->create([
            'user_id' => $user->id, 'type' => 'invoice', 'status' => $status,
            'currency' => 'CZK', 'issued_at' => '2026-06-01', 'due_at' => '2026-06-15', 'total' => 500,
        ]);
    }
    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'credit_note', 'status' => 'issued',
        'currency' => 'CZK', 'issued_at' => '2026-06-01', 'due_at' => '2026-06-15', 'total' => -500,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/receivables')
        ->assertOk();

    expect($response->json('data.receivables'))->toBe([]);
});

it('separates receivables per currency', function (): void {
    $user = createUser();

    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'sent',
        'currency' => 'CZK', 'issued_at' => '2026-07-01', 'due_at' => '2026-07-20', 'total' => 1000,
    ]);
    Invoice::factory()->create([
        'user_id' => $user->id, 'type' => 'invoice', 'status' => 'sent',
        'currency' => 'EUR', 'issued_at' => '2026-07-01', 'due_at' => '2026-07-20', 'total' => 40,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/receivables')
        ->assertOk();

    expect($response->json('data.receivables.CZK.not_yet_due.amount'))->toEqual(1000.0)
        ->and($response->json('data.receivables.EUR.not_yet_due.amount'))->toEqual(40.0);
});

it('reports payables with the full total (no partial payments) and a null due date as not yet due', function (): void {
    $user = createUser();

    SupplierInvoice::factory()->create([
        'user_id' => $user->id, 'status' => 'received',
        'currency' => 'CZK', 'issued_at' => '2026-07-01', 'due_at' => null, 'total' => 250,
    ]);
    SupplierInvoice::factory()->create([
        'user_id' => $user->id, 'status' => 'booked',
        'currency' => 'CZK', 'issued_at' => '2026-05-01', 'due_at' => '2026-05-15', 'total' => 750,
    ]);
    // Paid — must not appear.
    SupplierInvoice::factory()->create([
        'user_id' => $user->id, 'status' => 'paid',
        'currency' => 'CZK', 'issued_at' => '2026-05-01', 'due_at' => '2026-05-15', 'paid_at' => '2026-05-20', 'total' => 999,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/statistics/receivables')
        ->assertOk();

    $czk = $response->json('data.payables.CZK');

    expect($czk['not_yet_due']['amount'])->toEqual(250.0)
        ->and($czk['not_yet_due']['count'])->toBe(1)
        ->and($czk['d61_90']['amount'])->toEqual(750.0);
});
