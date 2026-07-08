<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

use Carbon\CarbonImmutable;

/**
 * How taxable_supply_at (DUZP) is derived from the issue date.
 */
enum TaxDateMode: string
{
    case IssueDate = 'issue_date';
    case PreviousMonthEnd = 'previous_month_end';

    public function label(): string
    {
        return match ($this) {
            self::IssueDate => 'Stejné jako datum vystavení',
            self::PreviousMonthEnd => 'Poslední den předchozího měsíce',
        };
    }

    public function resolve(CarbonImmutable $issueDate): CarbonImmutable
    {
        return match ($this) {
            self::IssueDate => $issueDate,
            self::PreviousMonthEnd => $issueDate->startOfMonth()->subDay(),
        };
    }
}
