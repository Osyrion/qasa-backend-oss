<?php

declare(strict_types=1);

use App\Modules\Calendar\Domain\Models\Event;

it('exports events as CSV with canonical headers', function (): void {
    $user = createUser();
    Event::factory()->for($user)->create([
        'title' => 'Standup',
        'starts_at' => '2026-08-03 10:00',
        'ends_at' => '2026-08-03 10:15',
    ]);

    $response = $this->actingAs($user)
        ->get('/api/v1/events/export/csv?from=2026-08-01&to=2026-08-31');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('events_2026-08-01_2026-08-31.csv');

    $csv = (string) $response->getContent();
    // Strip the UTF-8 BOM before inspecting the header row.
    $body = substr($csv, 0, 3) === "\xEF\xBB\xBF" ? substr($csv, 3) : $csv;
    $lines = array_values(array_filter(explode("\n", trim($body))));

    expect($lines[0])->toBe('title;description;location;color;is_all_day;starts_at;ends_at')
        ->and($csv)->toContain('Standup');
});

it('exports events as ICS', function (): void {
    $user = createUser();
    Event::factory()->for($user)->create([
        'title' => 'Standup',
        'starts_at' => '2026-08-03 10:00',
        'ends_at' => '2026-08-03 10:15',
    ]);
    Event::factory()->for($user)->allDay()->create(['title' => 'Holiday']);

    $response = $this->actingAs($user)->get('/api/v1/events/export/ics');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');

    $ics = (string) $response->getContent();

    expect($ics)->toContain('BEGIN:VCALENDAR')
        ->toContain('BEGIN:VEVENT')
        ->toContain('UID:')
        ->toContain('DTSTART')
        ->toContain('SUMMARY:Standup')
        ->toContain('VALUE=DATE');
});

it('does not export another tenant events', function (): void {
    $owner = createUser();
    $ownerEvent = Event::factory()->for($owner)->create(['title' => 'Owner event']);

    $other = createUser();

    $response = $this->actingAs($other)->get('/api/v1/events/export/csv');

    $response->assertOk();
    expect((string) $response->getContent())->not->toContain($ownerEvent->title);
});
