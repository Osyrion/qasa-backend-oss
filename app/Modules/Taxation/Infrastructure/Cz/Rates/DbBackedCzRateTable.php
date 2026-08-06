<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Cz\Rates;

use App\Modules\Taxation\Domain\Models\CzTaxRateParameterSet;

/**
 * Wraps one admin-edited row as a CzRateTable. forYear() is the only way to
 * get one — it returns null rather than an empty instance when no row
 * exists for the year, so the calculator can fall back to the hardcoded
 * CzRates{year} class without this class needing to know those exist.
 */
final readonly class DbBackedCzRateTable implements CzRateTable
{
    public function __construct(
        private CzTaxRateParameterSet $row,
    ) {}

    public static function forYear(int $year): ?self
    {
        $row = CzTaxRateParameterSet::query()->find($year);

        return $row === null ? null : new self($row);
    }

    public static function hasYear(int $year): bool
    {
        return CzTaxRateParameterSet::query()->where('year', $year)->exists();
    }

    public function year(): int
    {
        return $this->row->year;
    }

    public function lowTaxRate(): float
    {
        return $this->row->low_tax_rate;
    }

    public function highTaxRate(): float
    {
        return $this->row->high_tax_rate;
    }

    public function highRateThreshold(): float
    {
        return $this->row->high_rate_threshold;
    }

    public function basicTaxpayerCredit(): float
    {
        return $this->row->basic_taxpayer_credit;
    }

    public function spouseCredit(): float
    {
        return $this->row->spouse_credit;
    }

    /**
     * @return array{1: float, 2: float, 3: float}
     */
    public function childCredits(): array
    {
        $credits = $this->row->child_credits;

        return [
            1 => (float) $credits['1'],
            2 => (float) $credits['2'],
            3 => (float) $credits['3'],
        ];
    }

    /**
     * @return array<int, float>
     */
    public function flatRateIncomeCaps(): array
    {
        $caps = [];

        foreach ($this->row->flat_rate_income_caps as $percent => $cap) {
            $caps[(int) $percent] = (float) $cap;
        }

        return $caps;
    }

    public function socialContributionRate(): float
    {
        return $this->row->social_contribution_rate;
    }

    public function healthContributionRate(): float
    {
        return $this->row->health_contribution_rate;
    }

    public function assessmentBaseShare(): float
    {
        return $this->row->assessment_base_share;
    }

    public function minMonthlySocialBaseMainActivity(): float
    {
        return $this->row->min_monthly_social_base_main_activity;
    }

    public function minMonthlyHealthBaseMainActivity(): float
    {
        return $this->row->min_monthly_health_base_main_activity;
    }

    public function secondaryActivityThreshold(): float
    {
        return $this->row->secondary_activity_threshold;
    }
}
