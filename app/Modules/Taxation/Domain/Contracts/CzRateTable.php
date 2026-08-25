<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Contracts;

/**
 * Shape every CzRates{year} class must implement — see CzRates2025 for the
 * meaning of each figure and the verification caveat.
 */
interface CzRateTable extends RateTable
{
    public function lowTaxRate(): float;

    public function highTaxRate(): float;

    public function highRateThreshold(): float;

    public function basicTaxpayerCredit(): float;

    public function spouseCredit(): float;

    /**
     * @return array{1: float, 2: float, 3: float}
     */
    public function childCredits(): array;

    /**
     * @return array<int, float>
     */
    public function flatRateIncomeCaps(): array;

    public function socialContributionRate(): float;

    public function healthContributionRate(): float;

    public function assessmentBaseShare(): float;

    public function minMonthlySocialBaseMainActivity(): float;

    public function minMonthlyHealthBaseMainActivity(): float;

    public function secondaryActivityThreshold(): float;
}
