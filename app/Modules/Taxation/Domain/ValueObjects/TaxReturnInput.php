<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\ValueObjects;

use App\Modules\Taxation\Application\DTOs\SystemIncomeData;

/**
 * Everything an IncomeTaxReturnCalculator needs — system-derived income plus
 * the wizard's personal-circumstances answers. One shared shape for both
 * SK/CZ; each calculator reads only the fields its legislation needs.
 */
final readonly class TaxReturnInput
{
    /**
     * @param  list<int>  $childrenAges  Age of each dependent child at the end of the tax year
     */
    public function __construct(
        public int $year,
        public SystemIncomeData $systemIncome,
        public bool $useActualExpenses,
        public ?int $flatRateCategoryPercent,
        public bool $isMainActivity,
        public int $monthsActive,
        public array $childrenAges,
        public bool $spouseEligibleForCredit,
        public float $employmentIncome,
        public float $otherIncome,
        public float $foreignIncome,
    ) {}
}
