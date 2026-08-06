<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk;

use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Domain\Contracts\IncomeTaxReturnCalculator;
use App\Modules\Taxation\Domain\ValueObjects\TaxReturnInput;
use App\Modules\Taxation\Domain\ValueObjects\TaxReturnResult;
use App\Modules\Taxation\Infrastructure\Sk\Rates\DbBackedSkRateTable;
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
        return isset($this->rateTables[$year]) || DbBackedSkRateTable::hasYear($year);
    }

    public function calculate(TaxReturnInput $input): TaxReturnResult
    {
        $rates = $this->ratesFor($input->year);

        $businessIncome = $input->systemIncome->businessIncome;
        $expensesUsed = $this->expensesUsed($input, $rates, $businessIncome);
        $partialBaseBusiness = max(0.0, round($businessIncome - $expensesUsed, 2));

        $totalTaxBase = round($partialBaseBusiness + $input->employmentIncome + $input->otherIncome, 2);

        $nczd = $this->basicAllowance($totalTaxBase, $rates);
        $spouseNczd = $input->spouseEligibleForCredit ? $this->spouseAllowance($totalTaxBase, $rates) : 0.0;

        $adjustedBase = max(0.0, round($totalTaxBase - $nczd - $spouseNczd, 2));

        $tax = $this->tax($businessIncome, $adjustedBase, $partialBaseBusiness, $totalTaxBase, $rates);

        $childBonus = $this->childBonus($input->childrenAges, $partialBaseBusiness, $rates);
        $finalTax = round($tax - $childBonus, 2);

        $contributions = $this->contributions($input, $rates, $partialBaseBusiness, $businessIncome);

        $notes = [];

        if ($input->foreignIncome > 0.0) {
            $notes[] = __('taxation.foreign_income_note');
        }

        // Only when the split actually decided something: business income
        // qualifying for the reduced rate *and* income from elsewhere in the
        // same base. See tax() for the assumption being flagged.
        if ($partialBaseBusiness > 0.0
            && $businessIncome <= $rates->lowRateIncomeThreshold()
            && round($totalTaxBase - $partialBaseBusiness, 2) > 0.0
        ) {
            $notes[] = __('taxation.mixed_income_rate_split_note');
        }

        if ($input->systemIncome->unconvertedAmounts !== []) {
            $notes[] = __('taxation.unconverted_amounts_note');
        }

        // The assessment base here is a straight share of the partial base,
        // bounded at neither end. SK law bounds it at both — and the error is
        // material in both directions: a small income lands under the
        // statutory minimum, a large one runs away without the ceiling
        // (€400k of income produces a five-figure over-estimate). The figures
        // themselves belong in SkRateTable once they have been read off the
        // Sociálna poisťovňa / health insurer tables for the year; until then
        // this says so rather than letting the number pass for a computed
        // liability. CzIncomeTaxReturnCalculator already applies its minimum
        // monthly bases.
        if ($contributions['social'] > 0.0 || $contributions['health'] > 0.0) {
            $notes[] = __('taxation.sk_contributions_unbounded_note');
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

    /**
     * An admin-edited row is a full replacement for the year, checked
     * before the hardcoded class — never merged with it.
     */
    private function ratesFor(int $year): SkRateTable
    {
        return DbBackedSkRateTable::forYear($year)
            ?? $this->rateTables[$year]
            ?? throw DomainException::because(__('taxation.unsupported_tax_year', ['year' => $year]));
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

    /**
     * The reduced rate is a §6 concession: it is gated on §6 business income
     * and applies to the §6 partial tax base. It used to be applied to the
     * whole adjusted base, so any base at all was taxed at 15% as long as
     * business income stayed under the threshold — including the case of no
     * business income whatsoever, where `0 <= threshold` held trivially and
     * an employee's entire salary came out at 15%. The 25% bracket was
     * skipped on that path too. Every test in the suite passed
     * employmentIncome: 0.0, so the mixed case the wizard accepts was never
     * exercised.
     *
     * How the allowances split between the two portions is a question for a
     * tax advisor, not for this class; the neutral choice is taken — what
     * survives NČZD is divided in the same proportion the two sources
     * contributed to the base before it — and surfaced as a note on the
     * result whenever it actually changes the answer.
     */
    private function tax(float $businessIncome, float $adjustedBase, float $partialBaseBusiness, float $totalTaxBase, SkRateTable $rates): float
    {
        if ($adjustedBase <= 0.0) {
            return 0.0;
        }

        $businessPortion = $this->reducedRatePortion($businessIncome, $adjustedBase, $partialBaseBusiness, $totalTaxBase, $rates);
        $remainder = round($adjustedBase - $businessPortion, 2);
        $threshold = $rates->highRateThreshold();

        $tax = $businessPortion * $rates->lowTaxRate();

        $tax += $remainder <= $threshold
            ? $remainder * $rates->standardTaxRate()
            : $threshold * $rates->standardTaxRate() + ($remainder - $threshold) * $rates->highTaxRate();

        return round($tax, 2);
    }

    /**
     * The slice of the adjusted base the 15% rate may be applied to: nothing
     * unless §6 income qualifies, and never more than the share the business
     * partial base contributed to the pre-allowance base.
     */
    private function reducedRatePortion(float $businessIncome, float $adjustedBase, float $partialBaseBusiness, float $totalTaxBase, SkRateTable $rates): float
    {
        if ($businessIncome > $rates->lowRateIncomeThreshold() || $partialBaseBusiness <= 0.0 || $totalTaxBase <= 0.0) {
            return 0.0;
        }

        $share = min(1.0, $partialBaseBusiness / $totalTaxBase);

        return round($adjustedBase * $share, 2);
    }

    /**
     * @param  list<int>  $childrenAges
     */
    private function childBonus(array $childrenAges, float $partialBaseBusiness, SkRateTable $rates): float
    {
        $eligible = array_values(array_filter($childrenAges, static fn (int $age): bool => $age <= 18));

        if ($eligible === []) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($eligible as $age) {
            $total += $age < 15 ? $rates->childBonusUnder15() : $rates->childBonus15To18();
        }

        // The cap band is keyed on how many children actually qualify, not on
        // how many the taxpayer listed — an adult child neither earns a bonus
        // nor widens the ceiling for their siblings.
        $cap = round($partialBaseBusiness * $rates->childBonusCapShare(count($eligible)), 2);

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
