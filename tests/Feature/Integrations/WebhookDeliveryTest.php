<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Integrations\Application\Jobs\DeliverWebhookJob;
use App\Modules\Integrations\Domain\Models\WebhookDelivery;
use App\Modules\Integrations\Domain\Models\WebhookEndpoint;
use App\Modules\Invoicing\Domain\Events\InvoiceCreated;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('dispatches a delivery job when a subscribed event fires', function (): void {
    Queue::fake();

    $user = createUser();
    $endpoint = WebhookEndpoint::factory()->create([
        'user_id' => $user->id,
        'events' => ['invoice.created'],
    ]);

    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    event(new InvoiceCreated($invoice));

    Queue::assertPushed(
        DeliverWebhookJob::class,
        fn (DeliverWebhookJob $job): bool => $job->webhookEndpointId === $endpoint->id && $job->event === 'invoice.created',
    );
});

it('does not dispatch to an endpoint that is not subscribed to the event', function (): void {
    Queue::fake();

    $user = createUser();
    WebhookEndpoint::factory()->create([
        'user_id' => $user->id,
        'events' => ['payment.recorded'],
    ]);

    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    event(new InvoiceCreated($invoice));

    Queue::assertNotPushed(DeliverWebhookJob::class);
});

it('does not dispatch to a disabled (inactive) endpoint', function (): void {
    Queue::fake();

    $user = createUser();
    WebhookEndpoint::factory()->disabled()->create([
        'user_id' => $user->id,
        'events' => ['invoice.created'],
    ]);

    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    event(new InvoiceCreated($invoice));

    Queue::assertNotPushed(DeliverWebhookJob::class);
});

it('signs the request body with the endpoint secret and records a successful delivery', function (): void {
    Http::fake(['example.com/*' => Http::response('ok', 200)]);

    $endpoint = WebhookEndpoint::factory()->create([
        'url' => 'https://example.com/hook',
        'secret' => 'test-secret',
    ]);

    (new DeliverWebhookJob($endpoint->id, 'invoice.created', ['id' => 'abc']))->handle();

    Http::assertSent(function ($request): bool {
        $expected = 'sha256='.hash_hmac('sha256', $request->body(), 'test-secret');

        return $request->hasHeader('X-Qasa-Signature', $expected)
            && $request->hasHeader('X-Qasa-Event', 'invoice.created')
            && $request->hasHeader('X-Qasa-Delivery');
    });

    $endpoint->refresh();
    expect($endpoint->consecutive_failures)->toBe(0)
        ->and($endpoint->last_success_at)->not->toBeNull();

    $delivery = WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->firstOrFail();
    expect($delivery->response_status)->toBe(200)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->failed_at)->toBeNull();
});

it('records a failed attempt and re-throws so the queue retries', function (): void {
    Http::fake(['example.com/*' => Http::response('server error', 500)]);

    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://example.com/hook']);

    expect(fn () => (new DeliverWebhookJob($endpoint->id, 'invoice.created', ['id' => 'abc']))->handle())
        ->toThrow(RuntimeException::class);

    $delivery = WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->firstOrFail();
    expect($delivery->response_status)->toBe(500)
        ->and($delivery->failed_at)->not->toBeNull();

    // consecutive_failures is only touched once retries are exhausted (failed()),
    // not on every intermediate attempt.
    expect($endpoint->refresh()->consecutive_failures)->toBe(0);
});

it('blocks delivery to an endpoint whose url resolves to a private address', function (): void {
    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://example.com/hook']);
    $endpoint->forceFill(['url' => 'https://127.0.0.1/hook'])->saveQuietly();

    expect(fn () => (new DeliverWebhookJob($endpoint->id, 'invoice.created', []))->handle())
        ->toThrow(RuntimeException::class);

    $delivery = WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->firstOrFail();
    expect($delivery->response_excerpt)->toContain('SSRF');
});

it('increments consecutive_failures and auto-disables at the threshold once retries are exhausted', function (): void {
    $endpoint = WebhookEndpoint::factory()->create(['consecutive_failures' => 9]);

    (new DeliverWebhookJob($endpoint->id, 'invoice.created', []))->failed(new RuntimeException('boom'));

    $endpoint->refresh();
    expect($endpoint->consecutive_failures)->toBe(10)
        ->and($endpoint->is_active)->toBeFalse()
        ->and($endpoint->disabled_at)->not->toBeNull();
});

it('does not disable the endpoint before the threshold is reached', function (): void {
    $endpoint = WebhookEndpoint::factory()->create(['consecutive_failures' => 2]);

    (new DeliverWebhookJob($endpoint->id, 'invoice.created', []))->failed(new RuntimeException('boom'));

    $endpoint->refresh();
    expect($endpoint->consecutive_failures)->toBe(3)
        ->and($endpoint->is_active)->toBeTrue()
        ->and($endpoint->disabled_at)->toBeNull();
});

it('resets consecutive_failures on the next successful delivery', function (): void {
    Http::fake(['example.com/*' => Http::response('ok', 200)]);

    $endpoint = WebhookEndpoint::factory()->create([
        'url' => 'https://example.com/hook',
        'consecutive_failures' => 5,
    ]);

    (new DeliverWebhookJob($endpoint->id, 'invoice.created', ['id' => 'abc']))->handle();

    expect($endpoint->refresh()->consecutive_failures)->toBe(0);
});

it('purges delivery logs older than the retention window', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();

    $old = WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'created_at' => now()->subDays(20),
    ]);
    $recent = WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'created_at' => now()->subDays(5),
    ]);

    $this->artisan('qasa:integrations:purge-webhook-deliveries')->assertSuccessful();

    expect(WebhookDelivery::find($old->id))->toBeNull()
        ->and(WebhookDelivery::find($recent->id))->not->toBeNull();
});
