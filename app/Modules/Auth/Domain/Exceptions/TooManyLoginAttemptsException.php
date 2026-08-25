<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * A DomainException, so it inherits the module's error convention and its
 * dontReport rule — a caller waiting out a backoff is not a fault worth
 * paging anyone for. It is the one login refusal that must not surface as
 * the usual 422 though: the caller has to be able to tell "wait" apart from
 * "wrong password", and needs the wait itself to say so. Presentation maps
 * it to 429 with Retry-After; see AuthController::login().
 */
class TooManyLoginAttemptsException extends DomainException
{
    private function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }

    public static function retryAfter(int $seconds): self
    {
        return new self(
            __('auth.too_many_attempts', ['minutes' => (int) ceil($seconds / 60)]),
            $seconds,
        );
    }
}
