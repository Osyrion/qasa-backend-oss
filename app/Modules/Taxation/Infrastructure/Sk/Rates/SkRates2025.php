<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk\Rates;

/**
 * SK personal income tax + odvody parameters for 2025.
 *
 * ⚠️ Figures below reflect the author's general knowledge of SK tax law and
 * are NOT a substitute for the official Finančná správa / Sociálna
 * poisťovňa tables for the filing year — verify every value here against
 * the current legislation before relying on this worksheet for an actual
 * filing (see docs/plans/TAX_RETURN_OSVC_PLAN.md decision 4).
 */
final readonly class SkRates2025 implements SkRateTable
{
    public function year(): int
    {
        return 2025;
    }

    public function flatExpenseRate(): float
    {
        return 0.60;
    }

    public function flatExpenseCap(): float
    {
        return 20_000.0;
    }

    public function lowRateIncomeThreshold(): float
    {
        return 60_000.0;
    }

    public function lowTaxRate(): float
    {
        return 0.15;
    }

    public function standardTaxRate(): float
    {
        return 0.19;
    }

    public function highTaxRate(): float
    {
        return 0.25;
    }

    public function highRateThreshold(): float
    {
        return 48_441.43;
    }

    public function lifeMinimum(): float
    {
        return 273.99;
    }

    public function socialContributionThreshold(): float
    {
        return 7_824.0;
    }

    public function socialContributionRate(): float
    {
        return 0.331;
    }

    public function healthContributionRate(): float
    {
        return 0.14;
    }

    public function assessmentBaseShare(): float
    {
        return 0.5;
    }

    public function childBonusUnder15(): float
    {
        return 1_680.0;
    }

    public function childBonus15To18(): float
    {
        return 600.0;
    }

    public function childBonusCapShare(int $children): float
    {
        // §33 ods. 6 — the cap widens with each additional eligible child and
        // stops widening at six. Keyed by ordinal like CzRateTable::childCredits().
        $bands = [1 => 0.20, 2 => 0.27, 3 => 0.34, 4 => 0.41, 5 => 0.48, 6 => 0.55];

        return $bands[min(max($children, 1), 6)];
    }
}
