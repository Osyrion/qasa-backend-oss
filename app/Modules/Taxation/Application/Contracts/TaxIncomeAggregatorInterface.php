<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Contracts;

use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use App\Modules\Taxation\Domain\ValueObjects\SystemIncomeData;

/**
 * The cash-basis figures a tax return is prefilled from: business income
 * actually collected, expenses actually paid, contributions paid.
 *
 * Published because the MCP tax-summary tool answers the same question from
 * Reports, and two implementations of "what did this account earn for tax
 * purposes" is exactly the kind of divergence a tax figure must not have.
 */
interface TaxIncomeAggregatorInterface
{
    public function aggregate(Account $user, int $year, TaxResidency $residency): SystemIncomeData;
}
