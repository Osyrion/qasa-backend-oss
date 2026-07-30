<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk;

use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Domain\Contracts\IncomeTaxReturnCalculator;
use App\Modules\Taxation\Domain\ValueObjects\TaxReturnInput;
use App\Modules\Taxation\Domain\ValueObjects\TaxReturnResult;
use App\Modules\Taxation\Infrastructure\Sk\Rates\SkRates2025;
use App\Modules\Taxation\Infrastructure\Sk\Rates\SkRates2026;
use App\Modules\Taxation\Infrastructure\Sk\Rates\SkRateTable;

/**
 * §6 (príjem z podnikania) worksheet — 60% flat-rate expenses (capped, plus
 * contributions actually paid — SK law adds those on top of the flat rate)
 * vs. actual expenses, NČZD on taxpayer + spouse (standard phase-out
 * formula), 15/19/25% tax, child tax bonus, and an estimated
 * social/health contribution assessment.
 */
final readonly class SkIncomeTaxReturnCalculator implements IncomeTaxReturnCalculator
{
    /** @var array<int, SkRateTable> */
    private array $rateTables;

    public function __construct()
    {
        $this->rateTables = [
            2025 => new SkRates2025,
            2026 => new SkRates2026,
        ];
    }

    public function supportsYear(int $year): bool
    {
        return isset($this->rateTables[$year]);
    }

    public function calculate(TaxReturnInput $input): TaxReturnResult
    {
        $rates = $this->rateTables[$input->year]
            ?? throw DomainException::because(__('taxation.unsupported_tax_year', ['year' => $input->year]));

        $businessIncome = $input->systemIncome->businessIncome;
        $expensesUsed = $this->expensesUsed($input, $rates, $businessIncome);
        $partialBaseBusiness = max(0.0, round($businessIncome - $expensesUsed, 2));

        $totalTaxBase = round($partialBaseBusiness + $input->employmentIncome + $input->otherIncome, 2);

        $nczd = $this->basicAllowance($totalTaxBase, $rates);
        $spouseNczd = $input->spouseEligibleForCredit ? $this->spouseAllowance($totalTaxBase, $rates) : 0.0;

        $adjustedBase = max(0.0, round($totalTaxBase - $nczd - $spouseNczd, 2));

        $tax = $this->tax($businessIncome, $adjustedBase, $rates);

        $childBonus = $this->childBonus($input->childrenAges, $partialBaseBusiness, $rates);
        $finalTax = round($tax - $childBonus, 2);

        $contributions = $this->contributions($input, $rates, $partialBaseBusiness, $businessIncome);

        $notes = [];

        if ($input->foreignIncome > 0.0) {
            $notes[] = __('taxation.foreign_income_note');
        }

        if ($input->systemIncome->unconvertedAmounts !== []) {
            $notes[] = __('taxation.unconverted_amounts_note');
        }

        return new TaxReturnResult(
            year: $input->year,
            currency: $input->systemIncome->currency->value,
            partialTaxBaseBusiness: $partialBaseBusiness,
            totalTaxBase: $totalTaxBase,
            expensesUsed: $expensesUsed,
            usedFlatRateExpenses: ! $input->useActualExpenses,
            taxBeforeCredits: $tax,
            taxCredits: round($nczd + $spouseNczd, 2),
            childTaxBonus: $childBonus,
            finalTax: $finalTax,
            advancesPaid: $input->systemIncome->incomeTaxAdvancesPaid,
            taxBalance: round($finalTax - $input->systemIncome->incomeTaxAdvancesPaid, 2),
            contributions: $contributions,
            notes: $notes,
        );
    }

    private function expensesUsed(TaxReturnInput $input, SkRateTable $rates, float $businessIncome): float
    {
        if ($input->useActualExpenses) {
            return $input->systemIncome->totalActualExpenses();
        }

        $flatExpense = min($businessIncome * $rates->flatExpenseRate(), $rates->flatExpenseCap());

        return round(
            $flatExpense
            + $input->systemIncome->socialContributionsPaid
            + $input->systemIncome->healthContributionsPaid,
            2,
        );
    }

    private function basicAllowance(float $totalTaxBase, SkRateTable $rates): float
    {
        $lm = $rates->lifeMinimum();
        $threshold = 92.8 * $lm;

        if ($totalTaxBase <= $threshold) {
            return round(21 * $lm, 2);
        }

        return round(max(0.0, 44.2 * $lm - $totalTaxBase / 4), 2);
    }

    private function spouseAllowance(float $totalTaxBase, SkRateTable $rates): float
    {
        $lm = $rates->lifeMinimum();
        $threshold = 176.8 * $lm;

        if ($totalTaxBase <= $threshold) {
            return round(19.5 * $lm, 2);
        }

        return round(max(0.0, 63.4 * $lm - $totalTaxBase / 4), 2);
    }

    private function tax(float $businessIncome, float $adjustedBase, SkRateTable $rates): float
    {
        if ($businessIncome <= $rates->lowRateIncomeThreshold()) {
            return round($adjustedBase * $rates->lowTaxRate(), 2);
        }

        $threshold = $rates->highRateThreshold();

        if ($adjustedBase <= $threshold) {
            return round($adjustedBase * $rates->standardTaxRate(), 2);
        }

        return round(
            $threshold * $rates->standardTaxRate() + ($adjustedBase - $threshold) * $rates->highTaxRate(),
            2,
        );
    }

    /**
     * @param  list<int>  $childrenAges
     */
    private function childBonus(array $childrenAges, float $partialBaseBusiness, SkRateTable $rates): float
    {
        $total = 0.0;

        foreach ($childrenAges as $age) {
            if ($age > 18) {
                continue;
            }

            $total += $age < 15 ? $rates->childBonusUnder15() : $rates->childBonus15To18();
        }

        $cap = round($partialBaseBusiness * $rates->childBonusCapShare(), 2);

        return round(min($total, $cap), 2);
    }

    /**
     * @return array<string, float>
     */
    private function contributions(TaxReturnInput $input, SkRateTable $rates, float $partialBaseBusiness, float $businessIncome): array
    {
        $annualAssessment = round($partialBaseBusiness * $rates->assessmentBaseShare(), 2);
        $months = max(0, min(12, $input->monthsActive));
        $proratedAssessment = round($annualAssessment * $months / 12, 2);

        $health = round($proratedAssessment * $rates->healthContributionRate(), 2);

        if ($businessIncome <= $rates->socialContributionThreshold()) {
            return ['social' => 0.0, 'health' => $health];
        }

        return [
            'social' => round($proratedAssessment * $rates->socialContributionRate(), 2),
            'health' => $health,
        ];
    }
}
