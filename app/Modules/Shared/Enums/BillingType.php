<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

enum BillingType: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Monthly = 'monthly';
    case FixedPerItem = 'fixed_per_item';
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::Hourly => 'Hodinová sadzba',
            self::Daily => 'Denná sadzba',
            self::Monthly => 'Mesačná sadzba',
            self::FixedPerItem => 'Úkonová sadzba',
            self::Mixed => 'Zmiešané (položky)',
        };
    }

    public function hasDefaultRate(): bool
    {
        return $this !== self::Mixed;
    }

    public function defaultUnit(): ?ItemUnit
    {
        return match ($this) {
            self::Hourly => ItemUnit::Hour,
            self::Daily => ItemUnit::Day,
            self::Monthly => ItemUnit::Month,
            self::FixedPerItem => ItemUnit::Piece,
            self::Mixed => null,
        };
    }
}
