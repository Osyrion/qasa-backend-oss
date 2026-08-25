<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Rules;

use App\Modules\Invoicing\Domain\ValueObjects\InvoiceNumberMask;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Lives in Shared because its only caller is Auth's profile DTO — the
 * numbering mask is set on the account, not on a document, which is why
 * InvoiceNumberingProfile and ProvidesInvoiceNumbering already sit here too.
 * Domain/Rules is not a published directory, so leaving it in Invoicing made
 * every caller reach into a closed one for a class Invoicing itself never
 * used.
 *
 * The parsing stays Invoicing's: InvoiceNumberMask is a published value
 * object and remains the single definition of what a well-formed mask is.
 */
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
