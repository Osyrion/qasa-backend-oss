<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Support;

use App\Modules\Taxation\Domain\Enums\VatFilingFrequency;
use App\Modules\Taxation\Domain\ValueObjects\VatFilingPeriod;
use Carbon\CarbonImmutable;

/**
 * SK and CZ share the same statutory VAT filing deadline: the 25th day of
 * the month following the period, for the return itself, the control
 * statement (KV DPH / DPH KH1) and the EU sales list (SV DPH) alike — this
 * is the well-established due date, not the form's internal structure, so
 * it carries none of the "unverified XSD" risk Part A is blocked on.
 *
 * Stateless by design: given "today", tells you which period (if any) has
 * its deadline exactly REMINDER_LEAD_DAYS away today. Quarterly filers only
 * ever get a period back in the month after a quarter closes.
 */
final class VatFilingDeadline
{
    private const int DEADLINE_DAY = 25;

    private const int REMINDER_LEAD_DAYS = 7;

    public static function reminderPeriod(VatFilingFrequency $frequency, CarbonImmutable $today): ?VatFilingPeriod
    {
        $deadline = $today->setDay(self::DEADLINE_DAY)->startOfDay();

        if (! $today->isSameDay($deadline->subDays(self::REMINDER_LEAD_DAYS))) {
            return null;
        }

        return match ($frequency) {
            VatFilingFrequency::Monthly => self::monthlyPeriod($deadline),
            VatFilingFrequency::Quarterly => self::quarterlyPeriod($deadline),
        };
    }

    private static function monthlyPeriod(CarbonImmutable $deadline): VatFilingPeriod
    {
        $closedMonth = $deadline->subMonthNoOverflow();

        return new VatFilingPeriod($closedMonth->year, null, $closedMonth->month, $deadline);
    }

    private static function quarterlyPeriod(CarbonImmutable $deadline): ?VatFilingPeriod
    {
        if (! in_array($deadline->month, [1, 4, 7, 10], true)) {
            return null;
        }

        $closedQuarterMonth = $deadline->subMonthNoOverflow();
        $quarter = intdiv($closedQuarterMonth->month - 1, 3) + 1;

        return new VatFilingPeriod($closedQuarterMonth->year, $quarter, null, $deadline);
    }
}
