<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Services;

use App\Modules\Auth\Domain\Contracts\PhoneVerificationProviderInterface;

/**
 * The default: no gateway configured, so nothing is ever sent.
 *
 * Returning false rather than throwing is what makes the core edition
 * coherent — a deployment with no SMS provider reports "verification is
 * unavailable right now" and carries on, which is the same thing callers
 * already have to handle when a real provider is down.
 */
class NullPhoneVerificationProvider implements PhoneVerificationProviderInterface
{
    public function sendCode(string $phone, string $code): bool
    {
        return false;
    }
}
