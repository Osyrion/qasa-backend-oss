<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SK IČO: 8 digits, valid when the 8-digit number is divisible by 11.
 */
final class ValidSkIco implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail(__('taxation.invalid_ico_format'));
        }
    }

    public static function isValid(string $value): bool
    {
        if (preg_match('/^\d{8}$/', $value) !== 1) {
            return false;
        }

        return ((int) $value) % 11 === 0;
    }
}
