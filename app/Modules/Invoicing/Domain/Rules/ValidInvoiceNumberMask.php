<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Rules;

use App\Modules\Invoicing\Domain\Services\InvoiceNumberMask;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidInvoiceNumberMask implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Empty means "reset to the legacy default", not a validation error.
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! InvoiceNumberMask::isValid($value)) {
            $fail(__('invoicing.invalid_number_mask'));
        }
    }
}
