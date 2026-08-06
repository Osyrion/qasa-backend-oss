<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk\Rates;

/**
 * SK personal income tax + odvody parameters for 2026.
 *
 * ⚠️ At the time this was written, the official 2026 tables (životné
 * minimum indexation, contribution thresholds) had not yet been published —
 * figures are the 2025 baseline with the customary indexation applied.
 * Treat as a placeholder: verify against Finančná správa / Sociálna
 * poisťovňa once published (see docs/plans/TAX_RETURN_OSVC_PLAN.md decision 4).
 */
final readonly class SkRates2026 implements SkRateTable
{
    public function year(): int
    {
        return 2026;
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
        return 50_222.36;
    }

    public function lifeMinimum(): float
    {
        return 284.32;
    }

    public function socialContributionThreshold(): float
    {
        return 8_112.0;
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
