<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Contracts;

interface CaptchaVerifierInterface
{
    /**
     * True when the token is acceptable — including when captcha
     * verification is turned off entirely (see services.turnstile.enabled).
     */
    public function verify(?string $token, ?string $remoteIp): bool;
}
