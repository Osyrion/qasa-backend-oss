<?php

declare(strict_types=1);

use App\Modules\Calendar\Domain\Enums\EventSource;
use App\Modules\Calendar\Domain\Models\Event;
use Illuminate\Http\UploadedFile;

/**
 * @param  list<string>  $veventBlocks
 */
function fakeIcsUpload(array $veventBlocks): UploadedFile
{
    $content = implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Test//Test//EN',
        ...$veventBlocks,
        'END:VCALENDAR',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'events').'.ics';
    file_put_contents($path, $content);

    return new UploadedFile($path, 'events.ics', 'text/calendar', null, true);
}

/**
 * @param  array<string, string>  $fields
 */
function vevent(array $fields): string
{
    $lines = ['BEGIN:VEVENT'];
    foreach ($fields as $key => $value) {
        $lines[] = "{$key}:{$value}";
    }
    $lines[] = 'END:VEVENT';

    return implode("\r\n", $lines);
}

it('maps ICS fields and stores the UID', function (): void {
    $user = createUser();

    $file = fakeIcsUpload([vevent([
        'UID' => 'event-1@example.com',
        'SUMMARY' => 'Standup',
        'DESCRIPTION' => 'Daily sync',
        'LOCATION' => 'Office',
        'DTSTART' => '20260803T100000',
        'DTEND' => '20260803T110000',
    ])]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/events/import/ics', ['file' => $file])
        ->assertOk();

    expect($response->json('created'))->toBe(1);

    $event = Event::query()->firstOrFail();
    expect($event)
        ->title->toBe('Standup')
        ->description->toBe('Daily sync')
        ->location->toBe('Office')
        ->external_uid->toBe('event-1@example.com')
        ->source->toBe(EventSource::IcsImport);
});

it('skips duplicates on re-import by UID', function (): void {
    $user = createUser();

    $block = vevent([
        'UID' => 'event-dup@example.com',
        'SUMMARY' => 'Standup',
        'DTSTART' => '20260803T100000',
        'DTEND' => '20260803T110000',
    ]);

    $this->actingAs($user)->postJson('/api/v1/events/import/ics', ['file' => fakeIcsUpload([$block])])->assertOk();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/events/import/ics', ['file' => fakeIcsUpload([$block])])
        ->assertOk();

    expect($response->json('created'))->toBe(0)
        ->and($response->json('skipped'))->toBe(1);

    expect(Event::query()->count())->toBe(1);
});

it('converts a UTC DTSTART to the configured local timezone', function (): void {
    config(['calendar.timezone' => 'Europe/Bratislava']);
    $user = createUser();

    // 08:00 UTC in August = 10:00 CEST (Europe/Bratislava, UTC+2 DST)
    $file = fakeIcsUpload([vevent([
        'UID' => 'event-utc@example.com',
        'SUMMARY' => 'UTC event',
        'DTSTART' => '20260803T080000Z',
        'DTEND' => '20260803T090000Z',
    ])]);

    $this->actingAs($user)->postJson('/api/v1/events/import/ics', ['file' => $file])->assertOk();

    $event = Event::query()->firstOrFail();
    expect($event->starts_at->format('H:i'))->toBe('10:00');
});

it('snaps unaligned times to the slot grid', function (): void {
    $user = createUser();

    $file = fakeIcsUpload([vevent([
        'UID' => 'event-snap@example.com',
        'SUMMARY' => 'Unaligned',
        'DTSTART' => '20260803T100700',
        'DTEND' => '20260803T105200',
    ])]);

    $this->actingAs($user)->postJson('/api/v1/events/import/ics', ['file' => $file])->assertOk();

    $event = Event::query()->firstOrFail();
    expect($event->starts_at->format('H:i'))->toBe('10:00')
        ->and($event->ends_at->format('H:i'))->toBe('11:00');
});

it('routes recurring and multi-day VEVENTs to errors', function (): void {
    $user = createUser();

    $recurring = vevent([
        'UID' => 'event-recurring@example.com',
        'SUMMARY' => 'Recurring',
        'DTSTART' => '20260803T100000',
        'DTEND' => '20260803T110000',
        'RRULE' => 'FREQ=DAILY;COUNT=5',
    ]);

    $multiDay = vevent([
        'UID' => 'event-multiday@example.com',
        'SUMMARY' => 'Conference',
        'DTSTART' => '20260803T100000',
        'DTEND' => '20260805T100000',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/events/import/ics', ['file' => fakeIcsUpload([$recurring, $multiDay])])
        ->assertOk();

    expect($response->json('created'))->toBe(0)
        ->and($response->json('errors'))->toHaveCount(2);

    expect(Event::query()->count())->toBe(0);
});

it('maps a VALUE=DATE event to an all-day event', function (): void {
    $user = createUser();

    $file = fakeIcsUpload([vevent([
        'UID' => 'event-allday@example.com',
        'SUMMARY' => 'Company holiday',
        'DTSTART;VALUE=DATE' => '20260810',
        'DTEND;VALUE=DATE' => '20260811',
    ])]);

    $this->actingAs($user)->postJson('/api/v1/events/import/ics', ['file' => $file])->assertOk();

    $event = Event::query()->firstOrFail();
    expect($event->is_all_day)->toBeTrue()
        ->and($event->starts_at->format('Y-m-d H:i'))->toBe('2026-08-10 00:00')
        ->and($event->ends_at->format('Y-m-d H:i'))->toBe('2026-08-11 00:00');
});
