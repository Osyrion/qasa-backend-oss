<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Domain\Models\ActivityLog;

it('records an activity entry when a client is created', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/clients', [
        'client_type' => 'company',
        'company_name' => 'ACME s.r.o.',
        'is_vat_payer' => false,
        'country' => 'SK',
        'currency' => 'EUR',
        'locale' => 'sk',
    ])->assertCreated();

    $entry = ActivityLog::where('user_id', $user->id)->where('event', 'client.created')->firstOrFail();

    expect($entry->subject_type)->toBe('client')
        ->and($entry->actor_id)->toBe($user->id);
});

it('records an invoice.status_changed entry with old and new status', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => InvoiceStatus::Draft->value,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/status", [
            'status' => 'sent',
        ])
        ->assertOk();

    $entry = ActivityLog::where('subject_type', 'invoice')
        ->where('subject_id', $invoice->id)
        ->where('event', 'invoice.status_changed')
        ->firstOrFail();

    expect($entry->changes)->toBe(['from' => 'draft', 'to' => 'sent']);
});

it('lists only the authenticated account\'s activity, newest first, filterable by subject', function (): void {
    $user = createUser();
    $other = createUser();

    $client = Client::factory()->create(['user_id' => $user->id]);
    ActivityLog::factory()->count(2)->create([
        'user_id' => $user->id,
        'subject_type' => 'client',
        'subject_id' => $client->id,
        'event' => 'client.updated',
    ]);
    ActivityLog::factory()->create(['user_id' => $other->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity');

    $response->assertOk()->assertJsonCount(2, 'data');

    $filtered = $this->actingAs($user)->getJson("/api/v1/activity?subject_type=client&subject_id={$client->id}");
    $filtered->assertOk()->assertJsonCount(2, 'data');
});

it('rejects unauthenticated access', function (): void {
    $this->getJson('/api/v1/activity')->assertUnauthorized();
});
