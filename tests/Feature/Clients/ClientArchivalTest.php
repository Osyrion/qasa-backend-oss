<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;

it('archives a client and excludes it from the default list', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'is_customer' => true]);

    $this->actingAs($user)->postJson("/api/v1/clients/{$client->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.is_archived', true);

    expect($client->fresh()?->archived_at)->not->toBeNull();

    $this->actingAs($user)->getJson('/api/v1/clients')
        ->assertOk()
        ->assertJsonMissing(['id' => $client->id]);

    $this->actingAs($user)->getJson('/api/v1/clients?status=archived')
        ->assertOk()
        ->assertJsonPath('data.0.id', $client->id);
});

it('restores an archived client', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'archived_at' => now()]);

    $this->actingAs($user)->postJson("/api/v1/clients/{$client->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.is_archived', false);

    expect($client->fresh()?->archived_at)->toBeNull();
});

it('excludes archived clients from plan limit counters', function (): void {
    $user = createUser();
    Client::factory()->count(5)->create(['user_id' => $user->id, 'is_customer' => true, 'archived_at' => now()]);

    // Free tier: max_customers = 5. All 5 existing customers are archived,
    // so a new one must still fit.
    $this->actingAs($user)->postJson('/api/v1/clients', [
        'client_type' => 'individual',
        'name' => 'Ján',
        'surname' => 'Nový',
        'is_customer' => true,
        'is_vat_payer' => false,
        'country' => 'SK',
        'currency' => 'EUR',
        'locale' => 'sk',
    ])->assertCreated();
});

it('blocks creating an invoice for an archived client', function (): void {
    $user = createUser(['default_currency' => 'EUR']);
    $client = Client::factory()->create(['user_id' => $user->id, 'archived_at' => now()]);

    $this->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => now()->toDateString(),
        'due_at' => now()->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ])->assertStatus(422)->assertJsonPath('message', __('clients.archived'));
});
