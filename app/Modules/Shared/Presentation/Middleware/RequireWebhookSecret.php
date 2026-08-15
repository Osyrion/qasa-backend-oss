<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed guard for signature-verified webhook routes.
 *
 * If the signing secret named by $configKey is unset/empty, the request is
 * rejected outright instead of letting the downstream verifier run with an
 * empty key. Stripe's signature check computes an HMAC with the configured
 * secret; with an empty secret that HMAC uses an empty key — one an attacker
 * can reproduce — so verification silently degrades into a no-op and forged
 * webhooks are accepted. A deploy that forgets to set the secret therefore
 * gets loud 500s here rather than quietly trusting spoofed events.
 *
 * The response body is machine-facing (Stripe), so it stays a plain string
 * like the controller's own 'Invalid signature' / 'OK' replies — no __() key.
 */
final class RequireWebhookSecret
{
    public function handle(Request $request, Closure $next, string $configKey): Response
    {
        if ((string) config($configKey) === '') {
            Log::error('Webhook rejected: signing secret not configured', ['config_key' => $configKey]);
            report("Webhook rejected: signing secret not configured ({$configKey})");

            return response('Webhook signing secret not configured', 500);
        }

        return $next($request);
    }
}
