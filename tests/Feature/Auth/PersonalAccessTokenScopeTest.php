<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;

it('creates a scoped token and returns the plaintext exactly once', function (): void {
    $user = createUser();

    $response = $this->actingAs($user)->postJson('/api/v1/auth/tokens', [
        'name' => 'zapier-integration',
        'abilities' => ['invoices.view'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('access_token.name', 'zapier-integration')
        ->assertJsonPath('access_token.abilities', ['invoices.view']);

    expect($response->json('token'))->toBeString();

    $index = $this->actingAs($user)->getJson('/api/v1/auth/tokens');
    $index->assertOk()->assertJsonCount(1);

    // The listing exposes only metadata — never the plaintext token again.
    expect($index->json('0'))->not->toHaveKey('token')
        ->not->toHaveKey('plainTextToken');
});

it('rejects an ability outside the catalogue', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/auth/tokens', [
        'name' => 'bad-token',
        'abilities' => ['nonsense.ability'],
    ])->assertUnprocessable();
});

it('revokes its own token but not another account token', function (): void {
    $owner = createUser();
    $stranger = createUser();

    $tokenId = $owner->createToken('to-revoke', ['invoices.view'])->accessToken->id;

    $this->actingAs($stranger)
        ->deleteJson("/api/v1/auth/tokens/{$tokenId}")
        ->assertNotFound();

    $this->actingAs($owner)
        ->deleteJson("/api/v1/auth/tokens/{$tokenId}")
        ->assertNoContent();

    expect($owner->tokens()->whereKey($tokenId)->exists())->toBeFalse();
});

it('lets a scoped token use the ability it was granted', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $token = $user->createToken('scoped', ['invoices.view'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/invoices')
        ->assertOk();
});

it('denies a scoped token an ability it was not granted', function (): void {
    $user = createUser();

    $token = $user->createToken('scoped', ['clients.view'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/invoices')
        ->assertForbidden();
});

it('lets the default full-access token use every ability', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $token = $user->createToken('login-token')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/invoices')
        ->assertOk();
});
