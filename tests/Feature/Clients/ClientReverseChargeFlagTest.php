<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;

it('rejects reverse_charge_allowed=true without a vat_id', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/clients', [
        'client_type' => 'company',
        'company_name' => 'ACME s.r.o.',
        'is_vat_payer' => false,
        'reverse_charge_allowed' => true,
        'country' => 'SK',
        'currency' => 'EUR',
        'locale' => 'sk',
    ])->assertStatus(422);
});

it('persists reverse_charge_allowed=true with a vat_id', function (): void {
    $user = createUser();

    $response = $this->actingAs($user)->postJson('/api/v1/clients', [
        'client_type' => 'company',
        'company_name' => 'ACME s.r.o.',
        'vat_id' => 'SK2020202020',
        'is_vat_payer' => true,
        'reverse_charge_allowed' => true,
        'country' => 'SK',
        'currency' => 'EUR',
        'locale' => 'sk',
    ]);

    $response->assertCreated()->assertJsonPath('reverse_charge_allowed', true);

    $client = Client::withoutGlobalScope('user')->findOrFail($response->json('id'));
    expect($client->reverse_charge_allowed)->toBeTrue();
});

it('rejects turning reverse_charge_allowed on via update without a vat_id', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'vat_id' => null]);

    $this->actingAs($user)->putJson("/api/v1/clients/{$client->id}", [
        'client_type' => $client->client_type,
        'company_name' => $client->company_name,
        'is_vat_payer' => false,
        'reverse_charge_allowed' => true,
        'country' => $client->country,
        'currency' => $client->currency->value,
        'locale' => $client->locale,
    ])->assertStatus(422);
});
