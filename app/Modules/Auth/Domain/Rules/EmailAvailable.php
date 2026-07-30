<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Rules;

use App\Modules\Shared\Support\AccountLookup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * users is tenant-scoped, and nothing is bound while this rule runs — a
 * plain `unique:users,email`/Rule::unique() query would see no rows
 * regardless of whether the address is taken, and report every email as
 * available. This goes through the same SECURITY DEFINER lookup login uses
 * instead, which answers the question without needing anything bound.
 */
final class EmailAvailable implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (AccountLookup::byEmail($value) !== null) {
            $fail(__('auth.email_already_taken'));
        }
    }
}
