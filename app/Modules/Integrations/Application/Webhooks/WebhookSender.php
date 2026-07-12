<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application\Webhooks;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Builds and sends one signed webhook HTTP request. Shared by the queued
 * DeliverWebhookJob and the synchronous "send a test ping" action so the
 * signing/timeout/redirect rules can't drift between the two call sites.
 */
final class WebhookSender
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function send(string $url, string $secret, string $wireEvent, array $payload): Response
    {
        $body = json_encode(['event' => $wireEvent, 'data' => $payload], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        return Http::withBody($body, 'application/json')
            ->withHeaders([
                'X-Qasa-Event' => $wireEvent,
                'X-Qasa-Delivery' => (string) Str::uuid(),
                'X-Qasa-Signature' => $signature,
            ])
            ->timeout(5)
            ->withOptions(['allow_redirects' => false])
            ->post($url);
    }
}
