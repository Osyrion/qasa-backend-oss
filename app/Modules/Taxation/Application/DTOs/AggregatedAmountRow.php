<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

/**
 * One amount pulled from a source table (invoice payment, supplier invoice,
 * expense, contribution), before currency conversion — TaxIncomeAggregator's
 * internal working unit.
 */
final readonly class AggregatedAmountRow
{
    public function __construct(
        public float $amount,
        public string $currency,
        public string $date,
    ) {}
}
