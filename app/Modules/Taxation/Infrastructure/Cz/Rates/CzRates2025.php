<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Cz\Rates;

use App\Modules\Taxation\Domain\Contracts\CzRateTable;

/**
 * CZ personal income tax + OSVČ contribution parameters for 2025.
 *
 * ⚠️ Figures below reflect the author's general knowledge of CZ tax law as
 * of the 2024 consolidation package and are NOT a substitute for the
 * official Pokyn GFŘ/MFČR tables for the filing year — verify every value
 * here against the current legislation before relying on this worksheet for
 * an actual filing (see docs/plans/TAX_RETURN_OSVC_PLAN.md decision 4).
 */
final readonly class CzRates2025 implements CzRateTable
{
    public function year(): int
    {
        return 2025;
    }

    public function lowTaxRate(): float
    {
        return 0.15;
    }

    public function highTaxRate(): float
    {
        return 0.23;
    }

    /** Annual tax base above which the 23% bracket applies (36× average wage). */
    public function highRateThreshold(): float
    {
        return 1_676_052.0;
    }

    /** Sleva na poplatníka — basic per-taxpayer credit, annual. */
    public function basicTaxpayerCredit(): float
    {
        return 30_840.0;
    }

    /**
     * Sleva na manžela/manželku — only available since 2024 when the couple
     * cares for a child under 3 years old, annual.
     */
    public function spouseCredit(): float
    {
        return 24_840.0;
    }

    /**
     * Daňové zvýhodnění na dítě — annual, per child by birth order.
     *
     * @return array{1: float, 2: float, 3: float}
     */
    public function childCredits(): array
    {
        return [1 => 15_204.0, 2 => 22_320.0, 3 => 27_840.0];
    }

    /**
     * Paušální výdaje — flat-rate expense category → [percent, income cap
     * the percentage applies to, in CZK].
     *
     * @return array<int, float>
     */
    public function flatRateIncomeCaps(): array
    {
        return [80 => 1_000_000.0, 60 => 1_500_000.0, 40 => 2_000_000.0, 30 => 2_000_000.0];
    }

    public function socialContributionRate(): float
    {
        return 0.292;
    }

    public function healthContributionRate(): float
    {
        return 0.135;
    }

    /** Share of the §7 partial tax base that forms the contributions assessment base. */
    public function assessmentBaseShare(): float
    {
        return 0.5;
    }

    /** Minimum monthly assessment base for social insurance — main activity. */
    public function minMonthlySocialBaseMainActivity(): float
    {
        return 12_527.0;
    }

    /** Minimum monthly assessment base for health insurance — main activity. */
    public function minMonthlyHealthBaseMainActivity(): float
    {
        return 21_982.0;
    }

    /**
     * Rozhodná částka — annual §7 income below which a secondary ("vedlejší")
     * activity has no social-insurance obligation at all.
     */
    public function secondaryActivityThreshold(): float
    {
        return 105_520.0;
    }
}
