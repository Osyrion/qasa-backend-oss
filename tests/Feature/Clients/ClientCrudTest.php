<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;

it('creates a client as customer by default when no role flags are sent', function (): void {
    $this->actingAs(createUser())
        ->postJson('/api/v1/clients', [
            'client_type' => 'individual',
            'name' => 'Ján',
            'surname' => 'Novák',
            'country' => 'SK',
            'currency' => 'EUR',
            'locale' => 'sk',
            'is_vat_payer' => false,
        ])
        ->assertCreated()
        ->assertJsonPath('is_customer', true)
        ->assertJsonPath('is_vendor', false);

    expect(Client::query()->first())
        ->is_customer->toBeTrue()
        ->is_vendor->toBeFalse();
});

it('creates a vendor-only client when is_vendor is sent', function (): void {
    $this->actingAs(createUser())
        ->postJson('/api/v1/clients', [
            'client_type' => 'individual',
            'name' => 'Ján',
            'surname' => 'Novák',
            'country' => 'SK',
            'currency' => 'EUR',
            'locale' => 'sk',
            'is_vat_payer' => false,
            'is_customer' => false,
            'is_vendor' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('is_customer', false)
        ->assertJsonPath('is_vendor', true);
});

it('rejects a client with both role flags false', function (): void {
    $this->actingAs(createUser())
        ->postJson('/api/v1/clients', [
            'client_type' => 'individual',
            'name' => 'Ján',
            'surname' => 'Novák',
            'country' => 'SK',
            'currency' => 'EUR',
            'locale' => 'sk',
            'is_vat_payer' => false,
            'is_customer' => false,
            'is_vendor' => false,
        ])
        ->assertStatus(422)
        ->assertJson(['message' => __('clients.role_required')]);
});

it('filters the client list by role', function (): void {
    $user = createUser();

    Client::factory()->for($user, 'user')->create();
    Client::factory()->for($user, 'user')->vendor()->create();
    Client::factory()->for($user, 'user')->both()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/clients')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($user)
        ->getJson('/api/v1/clients?role=vendor')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($user)
        ->getJson('/api/v1/clients?role=all')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('includes is_customer and is_vendor in the client resource', function (): void {
    $user = createUser();
    $client = Client::factory()->for($user, 'user')->both()->create();

    $this->actingAs($user)
        ->getJson("/api/v1/clients/{$client->id}")
        ->assertOk()
        ->assertJsonPath('data.is_customer', true)
        ->assertJsonPath('data.is_vendor', true);
});
