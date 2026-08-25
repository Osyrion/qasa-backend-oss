<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * The account behind this attempt has taken enough failures that the next
 * one has to prove it is a person. Deliberately not a refusal: the caller
 * can carry on immediately by solving the challenge, which is what keeps
 * this from becoming the account lockout the whole design avoids.
 *
 * Surfaces as the module's usual 422 rather than a status of its own — what
 * the client needs is the `captcha_required` flag beside the message, so it
 * knows to render the widget and resubmit. See AuthController::login().
 */
class CaptchaRequiredException extends DomainException
{
    public static function forLogin(): self
    {
        return new self(__('auth.captcha_required'));
    }
}
