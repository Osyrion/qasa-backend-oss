<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CZ IČO: 8 digits, weighted mod-11 check digit (d8) per the official
 * algorithm — weights 8..2 over d1..d7, remainder mod 11 mapped to the
 * check digit (0 → 1, 1 → 0, otherwise 11 - remainder).
 */
final class ValidCzIco implements ValidationRule
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

        $digits = array_map('intval', str_split($value));
        $sum = 0;

        foreach (range(0, 6) as $i) {
            $sum += $digits[$i] * (8 - $i);
        }

        $remainder = $sum % 11;

        $checkDigit = match (true) {
            $remainder === 0 => 1,
            $remainder === 1 => 0,
            default => 11 - $remainder,
        };

        return $checkDigit === $digits[7];
    }
}
