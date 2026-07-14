<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

use Carbon\CarbonImmutable;

enum RecurringPeriod: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannually = 'semiannually';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Měsíčně',
            self::Quarterly => 'Čtvrtletně',
            self::Semiannually => 'Pololetně',
            self::Yearly => 'Ročně',
        };
    }

    public function monthsToAdd(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Semiannually => 6,
            self::Yearly => 12,
        };
    }

    /**
     * Next occurrence after $from. Re-anchors to start of month before
     * re-applying the intended day, so the day never erodes across short
     * months (no Carbon month-overflow drift).
     */
    public function nextDate(CarbonImmutable $from, int $dayOfMonth, bool $lastDayOfMonth): CarbonImmutable
    {
        $base = $from->startOfMonth()->addMonths($this->monthsToAdd());

        if ($lastDayOfMonth) {
            return $base->endOfMonth()->startOfDay();
        }

        return $base->setDay(min($dayOfMonth, $base->daysInMonth));
    }
}
