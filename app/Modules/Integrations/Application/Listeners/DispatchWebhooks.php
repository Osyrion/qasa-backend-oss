<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application\Listeners;

use App\Modules\Integrations\Application\Jobs\DeliverWebhookJob;
use App\Modules\Integrations\Application\Webhooks\WebhookEventMap;
use App\Modules\Integrations\Domain\Models\WebhookEndpoint;

/**
 * Fans a mapped domain event out to every active, subscribed webhook
 * endpoint of its owning account, one DeliverWebhookJob per endpoint. Runs
 * synchronously (it's just a lookup) — the slow/risky part, the actual HTTP
 * delivery, is what DeliverWebhookJob queues.
 */
class DispatchWebhooks
{
    public function handle(object $event): void
    {
        $wireEvent = WebhookEventMap::wireNameFor($event);

        if ($wireEvent === null) {
            return;
        }

        $userId = WebhookEventMap::ownerIdFor($event);

        if ($userId === null) {
            return;
        }

        $payload = WebhookEventMap::payloadFor($event);

        WebhookEndpoint::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get()
            ->each(function (WebhookEndpoint $endpoint) use ($wireEvent, $payload): void {
                if ($endpoint->subscribesTo($wireEvent)) {
                    DeliverWebhookJob::dispatch($endpoint->id, $wireEvent, $payload);
                }
            });
    }
}
