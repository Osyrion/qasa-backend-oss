<?php

declare(strict_types=1);

use App\Modules\Calendar\Domain\Models\Event;

it('returns events overlapping the given range', function (): void {
    $user = createUser();

    $before = Event::factory()->for($user)->create([
        'starts_at' => '2026-07-01 10:00',
        'ends_at' => '2026-07-01 11:00',
    ]);
    $overlapsStart = Event::factory()->for($user)->create([
        'starts_at' => '2026-07-31 23:00',
        'ends_at' => '2026-08-01 01:00',
    ]);
    $inRange = Event::factory()->for($user)->create([
        'starts_at' => '2026-08-15 10:00',
        'ends_at' => '2026-08-15 11:00',
    ]);
    $after = Event::factory()->for($user)->create([
        'starts_at' => '2026-09-01 10:00',
        'ends_at' => '2026-09-01 11:00',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/events?from=2026-08-01&to=2026-08-31')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($overlapsStart->id)
        ->toContain($inRange->id)
        ->not->toContain($before->id)
        ->not->toContain($after->id);
});

it('rejects a range where to is before from', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->getJson('/api/v1/events?from=2026-08-31&to=2026-08-01')
        ->assertUnprocessable();
});

it('paginates the event list', function (): void {
    $user = createUser();
    Event::factory()->for($user)->count(3)->create();

    $this->actingAs($user)
        ->getJson('/api/v1/events?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
