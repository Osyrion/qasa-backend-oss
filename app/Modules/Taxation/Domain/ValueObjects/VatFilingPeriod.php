<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\ValueObjects;

use App\Modules\Taxation\Domain\Enums\VatFilingFrequency;
use Carbon\CarbonImmutable;

/**
 * A VAT filing period (month or quarter, never both) and its statutory
 * deadline.
 *
 * SK and CZ share that deadline: the 25th day of the month following the
 * period, for the return itself, the control statement (KV DPH / DPH KH1) and
 * the EU sales list (SV DPH) alike — the well-established due date, not the
 * form's internal structure, so it carries none of the "unverified XSD" risk
 * Part A is blocked on.
 */
final readonly class VatFilingPeriod
{
    private const int DEADLINE_DAY = 25;

    private const int REMINDER_LEAD_DAYS = 7;

    public function __construct(
        public int $year,
        public ?int $quarter,
        public ?int $month,
        public CarbonImmutable $deadline,
    ) {}

    /**
     * The period whose deadline falls exactly REMINDER_LEAD_DAYS after today,
     * or null on every other day.
     *
     * Stateless and deliberately single-day: a daily schedule hitting this
     * naturally fires once per period, with no "have I already reminded"
     * state to keep. Quarterly filers only ever get a period back in the
     * month after a quarter closes.
     *
     * A named constructor rather than a helper class of its own: the rule is
     * *when this period is due*, which is the period's own business, and a
     * module that must not reach into Taxation's internals can still ask it.
     */
    public static function dueForReminder(VatFilingFrequency $frequency, CarbonImmutable $today): ?self
    {
        $deadline = $today->setDay(self::DEADLINE_DAY)->startOfDay();

        if (! $today->isSameDay($deadline->subDays(self::REMINDER_LEAD_DAYS))) {
            return null;
        }

        return match ($frequency) {
            VatFilingFrequency::Monthly => self::monthly($deadline),
            VatFilingFrequency::Quarterly => self::quarterly($deadline),
        };
    }

    private static function monthly(CarbonImmutable $deadline): self
    {
        $closedMonth = $deadline->subMonthNoOverflow();

        return new self($closedMonth->year, null, $closedMonth->month, $deadline);
    }

    private static function quarterly(CarbonImmutable $deadline): ?self
    {
        if (! in_array($deadline->month, [1, 4, 7, 10], true)) {
            return null;
        }

        $closedQuarterMonth = $deadline->subMonthNoOverflow();
        $quarter = intdiv($closedQuarterMonth->month - 1, 3) + 1;

        return new self($closedQuarterMonth->year, $quarter, null, $deadline);
    }
}
