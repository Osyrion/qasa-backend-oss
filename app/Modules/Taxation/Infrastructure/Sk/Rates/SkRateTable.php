<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk\Rates;

use App\Modules\Taxation\Domain\Contracts\RateTable;

/**
 * Shape every SkRates{year} class must implement — see SkRates2025 for the
 * meaning of each figure and the verification caveat.
 */
interface SkRateTable extends RateTable
{
    public function flatExpenseRate(): float;

    public function flatExpenseCap(): float;

    /** Annual gross §6 income up to which the 15% simplified rate applies. */
    public function lowRateIncomeThreshold(): float;

    public function lowTaxRate(): float;

    public function standardTaxRate(): float;

    public function highTaxRate(): float;

    /** Tax-base threshold above which the 25% bracket applies (176.8× life minimum). */
    public function highRateThreshold(): float;

    public function lifeMinimum(): float;

    public function socialContributionThreshold(): float;

    public function socialContributionRate(): float;

    public function healthContributionRate(): float;

    public function assessmentBaseShare(): float;

    /** Annual child tax bonus, per child, by age band. */
    public function childBonusUnder15(): float;

    public function childBonus15To18(): float;

    /** Child bonus total is capped at this share of the business partial tax base. */
    public function childBonusCapShare(): float;
}
