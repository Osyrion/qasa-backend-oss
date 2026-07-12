<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

final class EventTimeNormalizer
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function normalizeAllDay(CarbonImmutable $startsAt): array
    {
        $day = $startsAt->startOfDay();

        return [$day, $day->addDay()];
    }

    /**
     * Snaps an interval to the configured slot grid: start floors down,
     * end ceils up, and the result always spans at least one slot.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function snapToGrid(CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $slotMinutes = (int) config('calendar.slot_minutes');

        $start = $this->floorToSlot($startsAt, $slotMinutes);
        $end = $this->ceilToSlot($endsAt, $slotMinutes);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->addMinutes($slotMinutes);
        }

        return [$start, $end];
    }

    public function assertSameDay(CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $endsOnStartDay = $endsAt->isSameDay($startsAt)
            || $endsAt->equalTo($startsAt->startOfDay()->addDay());

        if (! $endsOnStartDay) {
            throw new RuntimeException(__('calendar.import.crosses_midnight'));
        }
    }

    private function floorToSlot(CarbonImmutable $dateTime, int $slotMinutes): CarbonImmutable
    {
        $slotSeconds = $slotMinutes * 60;
        $totalSeconds = ($dateTime->hour * 3600) + ($dateTime->minute * 60) + $dateTime->second;
        $flooredSeconds = intdiv($totalSeconds, $slotSeconds) * $slotSeconds;

        return $dateTime->startOfDay()->addSeconds($flooredSeconds);
    }

    private function ceilToSlot(CarbonImmutable $dateTime, int $slotMinutes): CarbonImmutable
    {
        $slotSeconds = $slotMinutes * 60;
        $totalSeconds = ($dateTime->hour * 3600) + ($dateTime->minute * 60) + $dateTime->second;
        $ceiledSeconds = (int) ceil($totalSeconds / $slotSeconds) * $slotSeconds;

        return $dateTime->startOfDay()->addSeconds($ceiledSeconds);
    }
}
