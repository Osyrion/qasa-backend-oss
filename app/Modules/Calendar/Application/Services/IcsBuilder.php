<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Services;

use App\Modules\Calendar\Domain\Models\Event;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;

final class IcsBuilder
{
    /**
     * @param  iterable<Event>  $events
     */
    public function build(iterable $events): string
    {
        $vcalendar = new VCalendar;
        $vcalendar->add('PRODID', '-//QASA//Calendar//EN');

        foreach ($events as $event) {
            /** @var Component $vevent */
            $vevent = $vcalendar->add('VEVENT', [
                'UID' => $event->external_uid ?? $event->id.'@qasa',
                'SUMMARY' => $event->title,
            ]);

            if ($event->description !== null) {
                $vevent->add('DESCRIPTION', $event->description);
            }

            if ($event->location !== null) {
                $vevent->add('LOCATION', $event->location);
            }

            if ($event->is_all_day) {
                $vevent->add('DTSTART', $event->starts_at->format('Ymd'), ['VALUE' => 'DATE']);
                $vevent->add('DTEND', $event->ends_at->format('Ymd'), ['VALUE' => 'DATE']);
            } else {
                // Floating local time — no TZID/Z suffix, matches the naive
                // wall-clock storage used throughout the module.
                $vevent->add('DTSTART', $event->starts_at->format('Ymd\THis'));
                $vevent->add('DTEND', $event->ends_at->format('Ymd\THis'));
            }
        }

        return $vcalendar->serialize();
    }
}
