<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\AiCredential;
use Illuminate\Support\Facades\Http;

it('lists one entry per supported provider, key never included', function (): void {
    $user = createUser();
    AiCredential::factory()->for($user)->create(['api_key' => 'sk-ant-secret']);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/ai-credentials')
        ->assertOk();

    expect($response->json('data.0.provider'))->toBe('anthropic')
        ->and($response->json('data.0.has_key'))->toBeTrue()
        ->and($response->json())->not->toContain('sk-ant-secret');
});

it('lists a provider with no saved key as has_key false', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->getJson('/api/v1/ai-credentials')
        ->assertOk()
        ->assertJsonPath('data.0.has_key', false);
});

it('saves a BYOK key via upsert without ever returning it raw', function (): void {
    $user = createUser();

    $response = $this->actingAs($user)
        ->putJson('/api/v1/ai-credentials/anthropic', ['api_key' => 'sk-ant-secret'])
        ->assertOk();

    expect($response->json())->not->toContain('sk-ant-secret');

    $credential = AiCredential::query()->where('user_id', $user->id)->where('provider', 'anthropic')->first();
    expect($credential?->api_key)->toBe('sk-ant-secret');
});

it('resets verified_at/last_error when a key is replaced', function (): void {
    $user = createUser();
    AiCredential::factory()->for($user)->verified()->create(['last_error' => null]);

    $this->actingAs($user)
        ->putJson('/api/v1/ai-credentials/anthropic', ['api_key' => 'sk-ant-new'])
        ->assertOk();

    $credential = AiCredential::query()->where('user_id', $user->id)->where('provider', 'anthropic')->first();
    expect($credential?->verified_at)->toBeNull();
});

it('rejects an unsupported provider on upsert', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->putJson('/api/v1/ai-credentials/deepseek', ['api_key' => 'sk-x'])
        ->assertStatus(422);
});

it('deletes a saved key', function (): void {
    $user = createUser();
    AiCredential::factory()->for($user)->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/ai-credentials/anthropic')
        ->assertOk();

    expect(AiCredential::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('validates a working key against the provider', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => []])]);
    $user = createUser();
    AiCredential::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/ai-credentials/anthropic/test')
        ->assertOk()
        ->assertJsonPath('valid', true);
});

it('reports an invalid key as such', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'unauthorized'], 401)]);
    $user = createUser();
    AiCredential::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/ai-credentials/anthropic/test')
        ->assertOk()
        ->assertJsonPath('valid', false);
});

it('rejects testing when no key is saved', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->postJson('/api/v1/ai-credentials/anthropic/test')
        ->assertStatus(422);
});

it('throttles the test endpoint', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => []])]);
    $user = createUser();
    AiCredential::factory()->for($user)->create();

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($user)->postJson('/api/v1/ai-credentials/anthropic/test')->assertOk();
    }

    $this->actingAs($user)->postJson('/api/v1/ai-credentials/anthropic/test')->assertStatus(429);
});
