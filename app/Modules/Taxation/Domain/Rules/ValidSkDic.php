<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SK DIČ: 10 digits, no separate checksum (format-only, per the plan).
 */
final class ValidSkDic implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail(__('taxation.invalid_dic_format'));
        }
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^\d{10}$/', $value) === 1;
    }
}
