<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Cz\Rates;

use App\Modules\Taxation\Domain\Contracts\CzRateTable;

/**
 * CZ personal income tax + OSVČ contribution parameters for 2026.
 *
 * ⚠️ At the time this was written, the official 2026 tables had not yet been
 * published — figures are the 2025 baseline with the customary minimum
 * assessment base indexation applied. Treat as a placeholder: verify against
 * the MFČR/GFŘ tables once published, before relying on this for a real
 * 2026 filing (see docs/plans/TAX_RETURN_OSVC_PLAN.md decision 4).
 */
final readonly class CzRates2026 implements CzRateTable
{
    public function year(): int
    {
        return 2026;
    }

    public function lowTaxRate(): float
    {
        return 0.15;
    }

    public function highTaxRate(): float
    {
        return 0.23;
    }

    public function highRateThreshold(): float
    {
        return 1_753_200.0;
    }

    public function basicTaxpayerCredit(): float
    {
        return 30_840.0;
    }

    public function spouseCredit(): float
    {
        return 24_840.0;
    }

    /**
     * @return array{1: float, 2: float, 3: float}
     */
    public function childCredits(): array
    {
        return [1 => 15_204.0, 2 => 22_320.0, 3 => 27_840.0];
    }

    /**
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

    public function assessmentBaseShare(): float
    {
        return 0.5;
    }

    public function minMonthlySocialBaseMainActivity(): float
    {
        return 13_191.0;
    }

    public function minMonthlyHealthBaseMainActivity(): float
    {
        return 23_073.0;
    }

    public function secondaryActivityThreshold(): float
    {
        return 111_772.0;
    }
}
