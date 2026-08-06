<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk\Rates;

use App\Modules\Taxation\Domain\Models\SkTaxRateParameterSet;

/**
 * Wraps one admin-edited row as a SkRateTable. forYear() is the only way to
 * get one — it returns null rather than an empty instance when no row
 * exists for the year, so the calculator can fall back to the hardcoded
 * SkRates{year} class without this class needing to know those exist.
 */
final readonly class DbBackedSkRateTable implements SkRateTable
{
    public function __construct(
        private SkTaxRateParameterSet $row,
    ) {}

    public static function forYear(int $year): ?self
    {
        $row = SkTaxRateParameterSet::query()->find($year);

        return $row === null ? null : new self($row);
    }

    public static function hasYear(int $year): bool
    {
        return SkTaxRateParameterSet::query()->where('year', $year)->exists();
    }

    public function year(): int
    {
        return $this->row->year;
    }

    public function flatExpenseRate(): float
    {
        return $this->row->flat_expense_rate;
    }

    public function flatExpenseCap(): float
    {
        return $this->row->flat_expense_cap;
    }

    public function lowRateIncomeThreshold(): float
    {
        return $this->row->low_rate_income_threshold;
    }

    public function lowTaxRate(): float
    {
        return $this->row->low_tax_rate;
    }

    public function standardTaxRate(): float
    {
        return $this->row->standard_tax_rate;
    }

    public function highTaxRate(): float
    {
        return $this->row->high_tax_rate;
    }

    public function highRateThreshold(): float
    {
        return $this->row->high_rate_threshold;
    }

    public function lifeMinimum(): float
    {
        return $this->row->life_minimum;
    }

    public function socialContributionThreshold(): float
    {
        return $this->row->social_contribution_threshold;
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

    public function childBonusUnder15(): float
    {
        return $this->row->child_bonus_under_15;
    }

    public function childBonus15To18(): float
    {
        return $this->row->child_bonus_15_to_18;
    }

    public function childBonusCapShare(int $children): float
    {
        $band = (string) min(max($children, 1), 6);

        return (float) $this->row->child_bonus_cap_shares[$band];
    }
}
