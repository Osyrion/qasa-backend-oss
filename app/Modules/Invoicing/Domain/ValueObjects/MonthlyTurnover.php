<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;

/**
 * One month's worth of one currency, already summed by the database.
 *
 * Deliberately not a list of documents: a turnover report covers whatever
 * range the caller asks for, so the row count is unbounded and summing it in
 * PHP would load an account's entire history to produce a dozen numbers.
 * What crosses the boundary is the sum — see the note in
 * docs/plans/MODULE_BOUNDARY_MODEL_DEBT_PLAN.md about why open documents may
 * be bucketed in PHP and historical aggregates may not.
 */
final readonly class MonthlyTurnover
{
    public function __construct(
        public Currency $currency,
        /** Calendar month as `YYYY-MM`. */
        public string $month,
        public float $amount,
    ) {}
}
