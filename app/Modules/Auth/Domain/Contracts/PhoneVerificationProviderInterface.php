<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Contracts;

/**
 * Sends a one-time code to a phone number.
 *
 * Core defines the contract and binds a null implementation that never
 * sends; a premium module binds a real gateway over the top (the same
 * shape as TrackedWorkDates). Which provider — Twilio, a local
 * SK/CZ gateway — is then a binding, not a rewrite.
 *
 * Implementations must not throw. An unreachable gateway is an ordinary
 * outcome here, not an exceptional one: the caller turns `false` into a
 * "try again later" response, and registration has to keep working
 * regardless of what the provider is doing.
 */
interface PhoneVerificationProviderInterface
{
    /**
     * @param  string  $phone  E.164, as stored on the account.
     * @param  string  $code  The plaintext code to deliver. Never logged.
     * @return bool Whether the message was accepted for delivery.
     */
    public function sendCode(string $phone, string $code): bool;
}
