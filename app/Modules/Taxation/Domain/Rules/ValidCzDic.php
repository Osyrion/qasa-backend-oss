<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CZ DIČ / IČ DPH: prefix "CZ" + 8-10 digits (8 for a legal entity's
 * IČO-based DIČ, 9-10 for an individual's birth-number-based DIČ). Unlike
 * SK, Czech legislation uses the same identifier for DIČ and IČ DPH, so one
 * rule covers both fields.
 */
final class ValidCzDic implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail(__('taxation.invalid_dic_format'));
        }
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^CZ\d{8,10}$/', $value) === 1;
    }
}
