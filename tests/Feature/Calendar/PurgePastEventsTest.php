<?php

declare(strict_types=1);

use App\Modules\Calendar\Domain\Models\Event;

it('purges events that ended before the current month', function (): void {
    $user = createUser();

    $lastMonth = Event::factory()->for($user)->create([
        'starts_at' => '2026-06-15 10:00',
        'ends_at' => '2026-06-15 11:00',
    ]);
    $current = Event::factory()->for($user)->create([
        'starts_at' => '2026-07-15 10:00',
        'ends_at' => '2026-07-15 11:00',
    ]);

    $this->artisan('qasa:calendar:purge-past', ['--date' => '2026-07-01'])
        ->assertSuccessful();

    expect(Event::query()->find($lastMonth->id))->toBeNull();
    expect(Event::query()->find($current->id))->not->toBeNull();
});

it('keeps an event ending exactly at the cutoff (strict less-than)', function (): void {
    $user = createUser();

    $boundary = Event::factory()->for($user)->create([
        'starts_at' => '2026-06-30 23:00',
        'ends_at' => '2026-07-01 00:00',
    ]);

    $this->artisan('qasa:calendar:purge-past', ['--date' => '2026-07-01']);

    expect(Event::query()->find($boundary->id))->not->toBeNull();
});

it('force-deletes soft-deleted events too', function (): void {
    $user = createUser();

    $event = Event::factory()->for($user)->create([
        'starts_at' => '2026-06-15 10:00',
        'ends_at' => '2026-06-15 11:00',
    ]);
    $event->delete();

    $this->artisan('qasa:calendar:purge-past', ['--date' => '2026-07-01']);

    expect(Event::withTrashed()->find($event->id))->toBeNull();
});

it('uses the months_after_end retention mode when configured', function (): void {
    config([
        'calendar.retention.mode' => 'months_after_end',
        'calendar.retention.months_after_end' => 3,
    ]);

    $user = createUser();

    $outsideWindow = Event::factory()->for($user)->create([
        'starts_at' => '2026-03-31 10:00',
        'ends_at' => '2026-03-31 11:00',
    ]);
    $insideWindow = Event::factory()->for($user)->create([
        'starts_at' => '2026-04-02 10:00',
        'ends_at' => '2026-04-02 11:00',
    ]);

    $this->artisan('qasa:calendar:purge-past', ['--date' => '2026-07-01']);

    expect(Event::query()->find($outsideWindow->id))->toBeNull();
    expect(Event::query()->find($insideWindow->id))->not->toBeNull();
});

it('purges events across tenants', function (): void {
    $userA = createUser();
    $userB = createUser();

    $eventA = Event::factory()->for($userA)->create(['starts_at' => '2026-06-01 10:00', 'ends_at' => '2026-06-01 11:00']);
    $eventB = Event::factory()->for($userB)->create(['starts_at' => '2026-06-01 10:00', 'ends_at' => '2026-06-01 11:00']);

    $this->artisan('qasa:calendar:purge-past', ['--date' => '2026-07-01']);

    expect(Event::query()->find($eventA->id))->toBeNull();
    expect(Event::query()->find($eventB->id))->toBeNull();
});
