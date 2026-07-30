<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use App\Modules\Shared\Enums\Currency;

/**
 * What TaxIncomeAggregator found in the system for (user, year), already
 * converted into the tax return's filing currency (TaxResidency::returnCurrency()).
 * Purely computed — never user input, so this is a plain value object rather
 * than a validated Spatie Data class.
 */
final readonly class SystemIncomeData
{
    /**
     * @param  list<array{currency: string, amount: float, date: string}>  $unconvertedAmounts
     *                                                                                          Amounts that could not be converted (no exchange rate on record for
     *                                                                                          that date) — excluded from the totals above, surfaced so the wizard
     *                                                                                          can warn the user rather than silently understate income/expenses.
     */
    public function __construct(
        public int $year,
        public Currency $currency,
        public float $businessIncome,
        public float $supplierInvoiceExpenses,
        public float $otherExpenses,
        public float $socialContributionsPaid,
        public float $healthContributionsPaid,
        public float $incomeTaxAdvancesPaid,
        public array $unconvertedAmounts = [],
    ) {}

    public function totalActualExpenses(): float
    {
        return round(
            $this->supplierInvoiceExpenses
            + $this->otherExpenses
            + $this->socialContributionsPaid
            + $this->healthContributionsPaid,
            2,
        );
    }
}
