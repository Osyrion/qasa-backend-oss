<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Rules;

use App\Modules\Shared\Support\AccountLookup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a number another account has already verified.
 *
 * Same mechanism as EmailAvailable, and same reason: `users` is
 * tenant-scoped, so a plain unique rule would query the caller's own
 * account and report every number as free. AccountLookup::byPhone() goes
 * through the SECURITY DEFINER function that can see across accounts.
 *
 * Deliberately not applied at registration. A number already spoken for is
 * a reason to refuse *verification* — and with it the trial that hangs off
 * verification — never a reason to refuse an account. Blocking registration
 * on it would both contradict the one hard requirement this feature has and
 * turn the public register endpoint into a phone-number oracle.
 */
final class PhoneAvailable implements ValidationRule
{
    public function __construct(
        /** The account doing the asking; its own number must not block it. */
        private readonly ?string $exceptAccountId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $owner = AccountLookup::byPhone($value);

        if ($owner !== null && $owner !== $this->exceptAccountId) {
            $fail(__('auth.phone_already_taken'));
        }
    }
}
