<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SK IČ DPH: prefix "SK" + the same 10-digit body as ValidSkDic.
 */
final class ValidSkVatId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail(__('taxation.invalid_vat_id_format'));
        }
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^SK\d{10}$/', $value) === 1;
    }
}
