<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;

it('accepts an update whose expected_updated_at matches the current value', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->draft()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $show = $this->actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}")->assertOk();

    $this->actingAs($user)->putJson("/api/v1/invoices/{$invoice->id}", [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
        'expected_updated_at' => $show->json('data.updated_at'),
    ])->assertOk();
});

it('rejects an update whose expected_updated_at is stale, with 409 and current state', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->draft()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    /** @var \Illuminate\Support\Carbon $updatedAt */
    $updatedAt = $invoice->updated_at;
    $staleTimestamp = $updatedAt->subMinutes(5)->toISOString();

    $response = $this->actingAs($user)->putJson("/api/v1/invoices/{$invoice->id}", [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
        'expected_updated_at' => $staleTimestamp,
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('data.id', $invoice->id);
});

it('allows an update with no expected_updated_at at all (opt-in check)', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->draft()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->actingAs($user)->putJson("/api/v1/invoices/{$invoice->id}", [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ])->assertOk();
});
