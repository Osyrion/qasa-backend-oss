<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\ValueObjects;

/**
 * Structured breakdown of an IncomeTaxReturnCalculator run — a worksheet,
 * not a filing. taxBalance > 0 means an amount still owed on top of
 * advances already paid; < 0 means an overpayment.
 */
final readonly class TaxReturnResult
{
    /**
     * @param  array<string, float>  $contributions  e.g. ['social' => ..., 'health' => ...] — this
     *                                               year's estimated/owed contributions, not what
     *                                               was already recorded via ContributionPayment.
     * @param  list<string>  $notes  Disclaimers and caveats to surface in the UI (e.g. foreign
     *                               income, flat-tax regime warning).
     */
    public function __construct(
        public int $year,
        public string $currency,
        public float $partialTaxBaseBusiness,
        public float $totalTaxBase,
        public float $expensesUsed,
        public bool $usedFlatRateExpenses,
        public float $taxBeforeCredits,
        public float $taxCredits,
        public float $childTaxBonus,
        public float $finalTax,
        public float $advancesPaid,
        public float $taxBalance,
        public array $contributions,
        public array $notes,
    ) {}
}
