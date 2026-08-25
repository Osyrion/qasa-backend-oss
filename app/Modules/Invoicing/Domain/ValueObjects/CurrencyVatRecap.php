<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;

/**
 * One VAT rate's base, tax and total within one currency, summed over a
 * period.
 *
 * A {@see VatRecapRow} says what one document owes at one rate; this says what
 * a whole period does. Bounded by currencies times rates, which is why the
 * summed form is what crosses the module boundary — the documents behind it
 * are a whole account's history and must not.
 */
final readonly class CurrencyVatRecap
{
    public function __construct(
        public Currency $currency,
        public VatRecapRow $recap,
    ) {}
}
