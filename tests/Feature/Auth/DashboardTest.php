<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    // Freeze time mid-month, mid-Q3, so month/quarter/year boundaries are deterministic.
    Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('reports invoicing volume split by month, quarter and year', function (): void {
    $user = createUser();

    // Current month + quarter + year.
    Invoice::factory()->create([
        'user_id' => $user->id,
        'status' => 'sent',
        'issued_at' => '2026-07-10',
        'due_at' => '2026-07-24',
        'total' => 1000,
    ]);
    // Q2 (April): only the year bucket.
    Invoice::factory()->create([
        'user_id' => $user->id,
        'status' => 'paid',
        'issued_at' => '2026-04-10',
        'due_at' => '2026-04-24',
        'total' => 2000,
    ]);
    // Draft is not issued volume — excluded everywhere.
    Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'issued_at' => '2026-07-05',
        'due_at' => '2026-07-19',
        'total' => 500,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.invoices.volume.month', 1000)
        ->assertJsonPath('data.invoices.volume.quarter', 1000)
        ->assertJsonPath('data.invoices.volume.year', 3000);
});

it('reports overdue count and outstanding balance, respecting partial payments', function (): void {
    $user = createUser();

    // Overdue, partially paid: outstanding = 1000 - 300 = 700.
    $overdue = Invoice::factory()->create([
        'user_id' => $user->id,
        'status' => 'reminded',
        'issued_at' => '2026-06-01',
        'due_at' => '2026-06-15',
        'total' => 1000,
    ]);
    InvoicePayment::factory()->create([
        'invoice_id' => $overdue->id,
        'amount' => 300,
        'paid_at' => '2026-06-20',
    ]);

    // Sent but not yet due — not overdue.
    Invoice::factory()->create([
        'user_id' => $user->id,
        'status' => 'sent',
        'issued_at' => '2026-07-10',
        'due_at' => '2026-07-24',
        'total' => 5000,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.invoices.overdue.count', 1)
        ->assertJsonPath('data.invoices.overdue.amount', 700);
});

it('counts reminded invoices past due as overdue (payment-ledger statuses)', function (): void {
    $user = createUser();

    Invoice::factory()->create([
        'user_id' => $user->id,
        'status' => 'reminded',
        'issued_at' => '2026-05-01',
        'due_at' => '2026-05-15',
        'total' => 800,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.invoices.overdue.count', 1)
        ->assertJsonPath('data.invoices.overdue.amount', 800);
});

it('returns a continuous 12-month cash-in income trend', function (): void {
    $user = createUser();

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'status' => 'paid',
        'issued_at' => '2026-04-01',
        'due_at' => '2026-04-15',
        'total' => 3000,
    ]);
    InvoicePayment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 2000,
        'paid_at' => '2026-04-05',
    ]);
    InvoicePayment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 500,
        'paid_at' => '2026-06-20',
    ]);
    // Outside the 12-month window — must be excluded.
    InvoicePayment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 999,
        'paid_at' => '2025-01-10',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/dashboard')
        ->assertOk();

    $trend = $response->json('data.income_trend');

    expect($trend)->toHaveCount(12)
        ->and($trend[0]['month'])->toBe('2025-08')
        ->and($trend[11]['month'])->toBe('2026-07');

    $byMonth = collect($trend)->pluck('amount', 'month');

    expect($byMonth['2026-04'])->toEqual(2000)
        ->and($byMonth['2026-06'])->toEqual(500)
        ->and($byMonth['2026-07'])->toEqual(0);
});
