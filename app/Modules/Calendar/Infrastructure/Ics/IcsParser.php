<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Infrastructure\Ics;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

final class IcsParser
{
    /**
     * @return array{events: list<array<string, mixed>>, errors: list<array<string, mixed>>}
     */
    public function parse(string $icsContent): array
    {
        try {
            /** @var VCalendar $vcalendar */
            $vcalendar = Reader::read($icsContent);
        } catch (\Exception $e) {
            return ['events' => [], 'errors' => [['uid' => null, 'message' => $e->getMessage()]]];
        }

        $events = [];
        $errors = [];

        /** @var VEvent $vevent */
        foreach ($vcalendar->select('VEVENT') as $vevent) {
            $uid = isset($vevent->UID) ? (string) $vevent->UID : null;

            if (isset($vevent->RRULE)) {
                $errors[] = ['uid' => $uid, 'message' => __('calendar.import.recurring_not_supported')];

                continue;
            }

            if (! isset($vevent->DTSTART)) {
                $errors[] = ['uid' => $uid, 'message' => __('calendar.import.unsupported_format')];

                continue;
            }

            try {
                $isAllDay = ! $vevent->DTSTART->hasTime();
                $targetTimezone = new DateTimeZone((string) config('calendar.timezone'));

                $startsAt = $this->toLocal($vevent->DTSTART->getDateTime($targetTimezone), $isAllDay);

                if (isset($vevent->DTEND)) {
                    $endsAt = $this->toLocal($vevent->DTEND->getDateTime($targetTimezone), $isAllDay);
                } else {
                    $endsAt = $startsAt->addMinutes(15);
                }

                if (! $isAllDay && $endsAt->diffInDays($startsAt) >= 1) {
                    $errors[] = ['uid' => $uid, 'message' => __('calendar.import.multi_day_not_supported')];

                    continue;
                }

                $events[] = [
                    'title' => isset($vevent->SUMMARY) ? (string) $vevent->SUMMARY : '',
                    'description' => isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
                    'location' => isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
                    'is_all_day' => $isAllDay,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'external_uid' => $uid ?? md5($vevent->serialize()),
                ];
            } catch (\Exception $e) {
                $errors[] = ['uid' => $uid, 'message' => $e->getMessage()];
            }
        }

        return ['events' => $events, 'errors' => $errors];
    }

    private function toLocal(DateTimeImmutable $dateTime, bool $isAllDay): CarbonImmutable
    {
        if ($isAllDay) {
            return CarbonImmutable::instance($dateTime)->startOfDay();
        }

        $converted = CarbonImmutable::instance($dateTime)->setTimezone((string) config('calendar.timezone'));

        return CarbonImmutable::parse($converted->format('Y-m-d H:i:s'));
    }
}
