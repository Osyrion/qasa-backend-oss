<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Cz;

use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Application\Contracts\EffectiveRateTables;
use App\Modules\Taxation\Domain\Contracts\CzRateTable;
use App\Modules\Taxation\Domain\Contracts\IncomeTaxReturnCalculator;
use App\Modules\Taxation\Domain\ValueObjects\TaxReturnInput;
use App\Modules\Taxation\Domain\ValueObjects\TaxReturnResult;

/**
 * §7 (samostatná činnost) worksheet — real vs. flat-rate expenses (whichever
 * the wizard input specifies; picking the more favourable one is a wizard/UI
 * concern, not this calculator's), progressive 15/23% tax, basic + spouse +
 * per-child credits, and an estimated social/health contribution assessment.
 * A pure function of (input, RateTable) — see RateTable for the "why".
 */
final readonly class CzIncomeTaxReturnCalculator implements IncomeTaxReturnCalculator
{
    public function __construct(private EffectiveRateTables $rates) {}

    public function supportsYear(int $year): bool
    {
        return $this->rates->cz($year) !== null;
    }

    public function calculate(TaxReturnInput $input): TaxReturnResult
    {
        $rates = $this->ratesFor($input->year);

        $businessIncome = $input->systemIncome->businessIncome;
        $expensesUsed = $this->expensesUsed($input, $rates, $businessIncome);
        $partialBaseBusiness = max(0.0, round($businessIncome - $expensesUsed, 2));

        $totalTaxBase = $this->roundDownToHundred(
            $partialBaseBusiness + $input->employmentIncome + $input->otherIncome,
        );

        $tax = $this->progressiveTax($totalTaxBase, $rates);

        $credits = $rates->basicTaxpayerCredit()
            + ($input->spouseEligibleForCredit ? $rates->spouseCredit() : 0.0);

        $taxAfterCredits = max(0.0, round($tax - $credits, 2));

        $childCredit = $this->childCredit($input->childrenAges, $rates);
        $finalTax = round($taxAfterCredits - $childCredit, 2);

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
            taxCredits: $credits,
            childTaxBonus: $childCredit,
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
    private function ratesFor(int $year): CzRateTable
    {
        return $this->rates->cz($year)
            ?? throw DomainException::because(__('taxation.unsupported_tax_year', ['year' => $year]));
    }

    private function expensesUsed(TaxReturnInput $input, CzRateTable $rates, float $businessIncome): float
    {
        if ($input->useActualExpenses) {
            return $input->systemIncome->totalActualExpenses();
        }

        $percent = $input->flatRateCategoryPercent ?? 60;
        $caps = $rates->flatRateIncomeCaps();
        $incomeCap = $caps[$percent] ?? throw DomainException::because(__('taxation.invalid_flat_rate_category'));
        $cappedIncome = min($businessIncome, $incomeCap);

        return round($cappedIncome * $percent / 100, 2);
    }

    private function progressiveTax(float $totalTaxBase, CzRateTable $rates): float
    {
        $threshold = $rates->highRateThreshold();

        if ($totalTaxBase <= $threshold) {
            return round($totalTaxBase * $rates->lowTaxRate(), 2);
        }

        return round(
            $threshold * $rates->lowTaxRate() + ($totalTaxBase - $threshold) * $rates->highTaxRate(),
            2,
        );
    }

    /**
     * Daňové zvýhodnění na vyživované dítě — §35c, scaled by ordinal
     * (first/second/third-and-further), not by age.
     *
     * The age is deliberately not filtered here, unlike the SK bonus's hard
     * 18 cut-off: a CZ dependent child qualifies up to 26 while still
     * studying, and nothing in the wizard asks about study status, so the age
     * alone cannot decide eligibility. The caller lists the children they are
     * claiming for; this prices them.
     *
     * @param  list<int>  $childrenAges
     */
    private function childCredit(array $childrenAges, CzRateTable $rates): float
    {
        $bands = $rates->childCredits();
        $total = 0.0;

        foreach (array_keys($childrenAges) as $index) {
            $total += $bands[min($index + 1, 3)];
        }

        return round($total, 2);
    }

    /**
     * @return array<string, float>
     */
    private function contributions(TaxReturnInput $input, CzRateTable $rates, float $partialBaseBusiness, float $businessIncome): array
    {
        $monthlyAssessment = round($partialBaseBusiness * $rates->assessmentBaseShare() / 12, 2);

        if (! $input->isMainActivity && $businessIncome < $rates->secondaryActivityThreshold()) {
            return ['social' => 0.0, 'health' => 0.0];
        }

        $socialMonthly = $input->isMainActivity
            ? max($monthlyAssessment, $rates->minMonthlySocialBaseMainActivity())
            : $monthlyAssessment;

        $healthMonthly = $input->isMainActivity
            ? max($monthlyAssessment, $rates->minMonthlyHealthBaseMainActivity())
            : $monthlyAssessment;

        $months = max(0, min(12, $input->monthsActive));

        return [
            'social' => round($socialMonthly * $months * $rates->socialContributionRate(), 2),
            'health' => round($healthMonthly * $months * $rates->healthContributionRate(), 2),
        ];
    }

    private function roundDownToHundred(float $amount): float
    {
        return floor(max(0.0, $amount) / 100) * 100;
    }
}
