<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application\Jobs;

use App\Modules\Integrations\Application\Webhooks\WebhookSender;
use App\Modules\Integrations\Domain\Models\WebhookDelivery;
use App\Modules\Integrations\Domain\Models\WebhookEndpoint;
use App\Modules\Shared\Support\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Endpoint-level health (consecutive_failures / auto-disable) is only
     * touched once these retries are exhausted, in failed() — not on every
     * intermediate attempt.
     */
    public int $tries = 3;

    private const AUTO_DISABLE_THRESHOLD = 10;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $webhookEndpointId,
        public readonly string $event,
        public readonly array $payload,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::withoutGlobalScope('user')->find($this->webhookEndpointId);

        if ($endpoint === null || ! $endpoint->is_active) {
            return;
        }

        if (! WebhookUrlGuard::isSafe($endpoint->url)) {
            $this->recordDelivery($endpoint, null, 'Blocked: webhook URL failed the SSRF safety check.');

            throw new RuntimeException('Webhook URL is not safe to deliver to.');
        }

        try {
            $response = WebhookSender::send($endpoint->url, (string) $endpoint->secret, $this->event, $this->payload);
        } catch (Throwable $e) {
            $this->recordDelivery($endpoint, null, Str::limit($e->getMessage(), 1000));

            throw $e;
        }

        if ($response->successful()) {
            $this->recordDelivery($endpoint, $response->status(), Str::limit($response->body(), 1000), success: true);
            $endpoint->update(['consecutive_failures' => 0, 'last_success_at' => now()]);

            return;
        }

        $this->recordDelivery($endpoint, $response->status(), Str::limit($response->body(), 1000));

        throw new RuntimeException("Webhook endpoint responded with status {$response->status()}.");
    }

    public function failed(?Throwable $exception): void
    {
        $endpoint = WebhookEndpoint::withoutGlobalScope('user')->find($this->webhookEndpointId);

        if ($endpoint === null) {
            return;
        }

        $failures = $endpoint->consecutive_failures + 1;
        $disables = $failures >= self::AUTO_DISABLE_THRESHOLD;

        $endpoint->update([
            'consecutive_failures' => $failures,
            'last_failure_at' => now(),
            ...($disables ? ['is_active' => false, 'disabled_at' => now()] : []),
        ]);
    }

    private function recordDelivery(WebhookEndpoint $endpoint, ?int $status, string $excerpt, bool $success = false): void
    {
        WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => $this->event,
            'payload' => $this->payload,
            'attempt' => $this->attempts(),
            'response_status' => $status,
            'response_excerpt' => $excerpt,
            'delivered_at' => $success ? now() : null,
            'failed_at' => $success ? null : now(),
        ]);
    }
}
