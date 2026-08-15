<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed guard for webhook providers whose only verification mechanism
 * is HTTP Basic Auth embedded in the registered webhook URL (Postmark's
 * inbound webhook has no HMAC signature option — this is its officially
 * recommended protection). Same fail-closed reasoning as
 * RequireWebhookSecret: an unconfigured username/password would otherwise
 * let hash_equals('', '') accept an unauthenticated request.
 */
final class RequireBasicAuthWebhook
{
    public function handle(Request $request, Closure $next, string $usernameConfigKey, string $passwordConfigKey): Response
    {
        $expectedUsername = (string) config($usernameConfigKey);
        $expectedPassword = (string) config($passwordConfigKey);

        if ($expectedUsername === '' || $expectedPassword === '') {
            Log::error('Webhook rejected: basic auth credentials not configured', [
                'username_config_key' => $usernameConfigKey,
                'password_config_key' => $passwordConfigKey,
            ]);

            // Fail-closed is only half the protection: every webhook the
            // provider sends is being rejected with a 500 until someone
            // notices, and the provider gives up retrying long before a log
            // file gets read.
            report("Webhook rejected: basic auth credentials not configured ({$usernameConfigKey})");

            return response('Webhook credentials not configured', 500);
        }

        $suppliedUsername = (string) $request->getUser();
        $suppliedPassword = (string) $request->getPassword();

        if (! hash_equals($expectedUsername, $suppliedUsername) || ! hash_equals($expectedPassword, $suppliedPassword)) {
            return response('Unauthorized', 401);
        }

        return $next($request);
    }
}
