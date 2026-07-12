<?php

declare(strict_types=1);

use App\Modules\Integrations\Domain\Models\WebhookDelivery;
use App\Modules\Integrations\Domain\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;

it('creates a webhook endpoint and returns the secret exactly once', function (): void {
    $user = createUser();

    $response = $this->actingAs($user)->postJson('/api/v1/webhook-endpoints', [
        'url' => 'https://example.com/webhooks/qasa',
        'events' => ['invoice.created', 'invoice.paid'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('events', ['invoice.created', 'invoice.paid'])
        ->assertJsonPath('is_active', true);

    expect($response->json('secret'))->toBeString()->not->toBeEmpty();

    $id = $response->json('id');

    $show = $this->actingAs($user)->getJson("/api/v1/webhook-endpoints/{$id}");
    $show->assertOk()->assertJsonMissingPath('secret');
});

it('lists only the account own webhook endpoints', function (): void {
    $owner = createUser();
    WebhookEndpoint::factory()->create(['user_id' => $owner->id]);

    $stranger = createUser();
    WebhookEndpoint::factory()->create(['user_id' => $stranger->id]);

    $this->actingAs($owner)
        ->getJson('/api/v1/webhook-endpoints')
        ->assertOk()
        ->assertJsonCount(1);
});

it('rejects an event outside the catalogue', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/webhook-endpoints', [
        'url' => 'https://example.com/webhooks/qasa',
        'events' => ['bogus.event'],
    ])->assertUnprocessable();
});

it('rejects an unsafe (private-range) url', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/webhook-endpoints', [
        'url' => 'https://127.0.0.1/webhooks',
        'events' => ['invoice.created'],
    ])->assertUnprocessable();
});

it('rejects a plain http url outside local env', function (): void {
    config(['app.env' => 'production']);

    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/webhook-endpoints', [
        'url' => 'http://example.com/webhooks',
        'events' => ['invoice.created'],
    ])->assertUnprocessable();
});

it('allows a plain http url in the local environment', function (): void {
    config(['app.env' => 'local']);

    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/webhook-endpoints', [
        'url' => 'http://example.com/webhooks',
        'events' => ['invoice.created'],
    ])->assertCreated();
});

it('updates a webhook endpoint', function (): void {
    $user = createUser();
    $endpoint = WebhookEndpoint::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->putJson("/api/v1/webhook-endpoints/{$endpoint->id}", [
        'url' => 'https://example.com/webhooks/updated',
        'events' => ['invoice.paid'],
        'is_active' => false,
    ])
        ->assertOk()
        ->assertJsonPath('url', 'https://example.com/webhooks/updated')
        ->assertJsonPath('is_active', false);
});

it('does not let a user manage another account webhook endpoint', function (): void {
    $victim = createUser();
    $victimEndpoint = WebhookEndpoint::factory()->create(['user_id' => $victim->id]);

    $attacker = createUser();

    $this->actingAs($attacker)
        ->getJson("/api/v1/webhook-endpoints/{$victimEndpoint->id}")
        ->assertNotFound();

    $this->actingAs($attacker)
        ->deleteJson("/api/v1/webhook-endpoints/{$victimEndpoint->id}")
        ->assertNotFound();
});

it('deletes a webhook endpoint', function (): void {
    $user = createUser();
    $endpoint = WebhookEndpoint::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/webhook-endpoints/{$endpoint->id}")
        ->assertNoContent();

    expect(WebhookEndpoint::withoutGlobalScope('user')->find($endpoint->id))->toBeNull();
});

it('sends a synchronous test ping and logs the delivery', function (): void {
    Http::fake(['example.com/*' => Http::response('pong', 200)]);

    $user = createUser();
    $endpoint = WebhookEndpoint::factory()->create(['user_id' => $user->id, 'url' => 'https://example.com/hook']);

    $this->actingAs($user)
        ->postJson("/api/v1/webhook-endpoints/{$endpoint->id}/test")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('status', 200);

    expect(
        WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->where('event', 'ping')->exists()
    )->toBeTrue();
});

it('lists delivery attempts for an endpoint', function (): void {
    $user = createUser();
    $endpoint = WebhookEndpoint::factory()->create(['user_id' => $user->id]);
    WebhookDelivery::factory()->count(3)->create(['webhook_endpoint_id' => $endpoint->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/webhook-endpoints/{$endpoint->id}/deliveries")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});
