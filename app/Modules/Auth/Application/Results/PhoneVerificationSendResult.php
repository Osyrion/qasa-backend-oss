<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Results;

/**
 * Why SendPhoneVerificationCodeAction did or did not send.
 *
 * A return value rather than an exception because neither failure is the
 * caller's fault: DomainException maps to 422 (bootstrap/app.php), which
 * would tell a client its request was malformed when in truth the gateway
 * is down. These two become 503 — honest, and retryable.
 *
 * Caller error — a number somebody else has verified, a resend inside the
 * cooldown — does throw DomainException, and belongs at 422.
 */
enum PhoneVerificationSendResult
{
    case Sent;

    /**
     * Switched off deliberately: no provider bound (the core edition's
     * default), or an admin flipped qasa.features.phone_verification.
     */
    case Disabled;

    /**
     * A provider is configured and refused or could not be reached. The
     * distinction from Disabled matters to the client: this one is worth
     * retrying, and worth alerting on if it persists.
     */
    case Unavailable;
}
