<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

enum ExportPeriodBasis: string
{
    case Issue = 'issue';
    case Tax = 'tax';

    public function column(): string
    {
        return match ($this) {
            self::Issue => 'issued_at',
            self::Tax => 'taxable_supply_at',
        };
    }
}
