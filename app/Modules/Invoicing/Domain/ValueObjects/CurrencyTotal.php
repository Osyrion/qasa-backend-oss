<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;

/**
 * One currency's total over a window the caller chose — the smallest shape in
 * the analytic read API, for the questions that have no second dimension.
 */
final readonly class CurrencyTotal
{
    public function __construct(
        public Currency $currency,
        public float $amount,
    ) {}
}
