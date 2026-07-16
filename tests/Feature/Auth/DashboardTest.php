<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    // Freeze time mid-month, mid-Q3, so due-date/threshold boundaries are deterministic.
    Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('lists overdue invoices past the reminder threshold with a can_remind flag', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => 'klient@example.com']);

    // 44 days overdue — well past the default 14-day threshold.
    $overdue = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => 'sent',
        'issued_at' => '2026-05-18',
        'due_at' => '2026-06-01',
        'total' => 1000,
    ]);
    // 10 days overdue — inside the threshold, not listed.
    Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => 'sent',
        'issued_at' => '2026-06-21',
        'due_at' => '2026-07-05',
        'total' => 500,
    ]);
    // Exactly 14 days overdue — boundary, "more than N days" excludes it.
    Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => 'sent',
        'issued_at' => '2026-06-17',
        'due_at' => '2026-07-01',
        'total' => 200,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.threshold_days', 14)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $overdue->id)
        ->assertJsonPath('data.items.0.days_overdue', 44)
        ->assertJsonPath('data.items.0.balance', 1000)
        ->assertJsonPath('data.items.0.can_remind', true)
        ->assertJsonPath('data.items.0.can_remind_reason', null);
});

it('respects a custom per-tenant overdue reminder threshold', function (): void {
    $user = createUser(['overdue_reminder_days' => 60]);
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => 'klient@example.com']);

    // 44 days overdue — inside a 60-day threshold, not listed.
    Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => 'sent',
        'issued_at' => '2026-05-18',
        'due_at' => '2026-06-01',
        'total' => 1000,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.threshold_days', 60)
        ->assertJsonCount(0, 'data.items');
});

it('flags why an overdue invoice cannot be reminded', function (): void {
    $user = createUser();
    $withEmail = Client::factory()->create(['user_id' => $user->id, 'email' => 'klient@example.com']);
    $withoutEmail = Client::factory()->create(['user_id' => $user->id, 'email' => null]);

    // Issued (never emailed) — remind endpoint refuses non-sent statuses.
    Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $withEmail->id,
        'status' => 'issued',
        'issued_at' => '2026-05-01',
        'due_at' => '2026-05-15',
        'total' => 100,
    ]);
    // Reminded yesterday — still inside the cooldown window.
    Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $withEmail->id,
        'status' => 'reminded',
        'issued_at' => '2026-05-02',
        'due_at' => '2026-05-16',
        'last_reminded_at' => '2026-07-14 09:00:00',
        'reminder_count' => 1,
        'total' => 100,
    ]);
    // No client email anywhere.
    Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $withoutEmail->id,
        'status' => 'sent',
        'issued_at' => '2026-05-03',
        'due_at' => '2026-05-17',
        'total' => 100,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonCount(3, 'data.items');

    /** @var array<int, array<string, mixed>> $itemsJson */
    $itemsJson = $response->json('data.items');
    $items = collect($itemsJson);

    expect($items->pluck('can_remind')->all())->toBe([false, false, false])
        ->and($items->pluck('can_remind_reason')->filter()->count())->toBe(3);
});

it('excludes settled, draft, cancelled and foreign invoices from the reminder list', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => 'klient@example.com']);

    foreach (['paid', 'draft', 'cancelled'] as $status) {
        Invoice::factory()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'status' => $status,
            'issued_at' => '2026-05-01',
            'due_at' => '2026-05-15',
            'total' => 100,
        ]);
    }

    // Another tenant's overdue invoice must never leak in.
    $other = createUser();
    $otherClient = Client::factory()->create(['user_id' => $other->id, 'email' => 'iny@example.com']);
    Invoice::factory()->create([
        'user_id' => $other->id,
        'client_id' => $otherClient->id,
        'status' => 'sent',
        'issued_at' => '2026-05-01',
        'due_at' => '2026-05-15',
        'total' => 100,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonCount(0, 'data.items');
});

it('reports the outstanding balance per reminder item, respecting partial payments', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => 'klient@example.com']);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => 'sent',
        'issued_at' => '2026-05-01',
        'due_at' => '2026-05-15',
        'total' => 1000,
    ]);
    InvoicePayment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 300,
        'paid_at' => '2026-05-20',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.items.0.balance', 700);
});
