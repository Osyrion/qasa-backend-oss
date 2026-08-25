<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Clients;

use App\Modules\Shared\Application\Contracts\CaptchaVerifierInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 */
class TurnstileVerifier implements CaptchaVerifierInterface
{
    public function verify(?string $token, ?string $remoteIp): bool
    {
        if (! (bool) config('services.turnstile.enabled')) {
            return true;
        }

        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->retry(1, 300, throw: false)
                ->post((string) config('services.turnstile.verify_url'), array_filter([
                    'secret' => (string) config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]));
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
