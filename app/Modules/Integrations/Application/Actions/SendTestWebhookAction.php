<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application\Actions;

use App\Modules\Integrations\Application\Webhooks\WebhookSender;
use App\Modules\Integrations\Domain\Models\WebhookDelivery;
use App\Modules\Integrations\Domain\Models\WebhookEndpoint;
use App\Modules\Shared\Support\WebhookUrlGuard;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends a synchronous "ping" test delivery so a user can verify their
 * endpoint is reachable before relying on it — no queue, no retries.
 */
readonly class SendTestWebhookAction
{
    private const WIRE_EVENT = 'ping';

    /**
     * @return array{success: bool, status: int|null, body: string|null}
     */
    public function execute(WebhookEndpoint $endpoint): array
    {
        $payload = [
            'message' => 'This is a test delivery from Qasa.',
            'sent_at' => now()->toISOString(),
        ];

        if (! WebhookUrlGuard::isSafe($endpoint->url)) {
            $this->record($endpoint, $payload, null, 'Blocked: webhook URL failed the SSRF safety check.', false);

            return ['success' => false, 'status' => null, 'body' => null];
        }

        try {
            $response = WebhookSender::send($endpoint->url, (string) $endpoint->secret, self::WIRE_EVENT, $payload);
        } catch (Throwable $e) {
            $this->record($endpoint, $payload, null, Str::limit($e->getMessage(), 1000), false);

            return ['success' => false, 'status' => null, 'body' => $e->getMessage()];
        }

        $this->record($endpoint, $payload, $response->status(), Str::limit($response->body(), 1000), $response->successful());

        return ['success' => $response->successful(), 'status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(WebhookEndpoint $endpoint, array $payload, ?int $status, string $excerpt, bool $success): void
    {
        WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => self::WIRE_EVENT,
            'payload' => $payload,
            'attempt' => 1,
            'response_status' => $status,
            'response_excerpt' => $excerpt,
            'delivered_at' => $success ? now() : null,
            'failed_at' => $success ? null : now(),
        ]);
    }
}
