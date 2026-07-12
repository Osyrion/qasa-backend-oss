<?php

declare(strict_types=1);

use App\Modules\Calendar\Domain\Enums\EventSource;
use App\Modules\Calendar\Domain\Models\Event;

it('creates an event with valid aligned times', function (): void {
    $user = createUser();

    $response = $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Client meeting',
        'starts_at' => '2026-08-03 10:00',
        'ends_at' => '2026-08-03 11:00',
    ]);

    $response->assertCreated()
        ->assertJsonPath('title', 'Client meeting')
        ->assertJsonPath('source', 'manual');

    expect(Event::query()->first())
        ->title->toBe('Client meeting')
        ->source->toBe(EventSource::Manual);
});

it('allows an event ending exactly at midnight of the next day', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Late shift',
        'starts_at' => '2026-08-03 22:00',
        'ends_at' => '2026-08-04 00:00',
    ])->assertCreated();
});

it('lists, shows, updates and deletes an event', function (): void {
    $user = createUser();
    $event = Event::factory()->for($user)->create([
        'starts_at' => '2026-08-03 10:00',
        'ends_at' => '2026-08-03 11:00',
    ]);

    $this->actingAs($user)->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($user)->getJson("/api/v1/events/{$event->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $event->id);

    $this->actingAs($user)->putJson("/api/v1/events/{$event->id}", [
        'title' => 'Updated title',
        'starts_at' => '2026-08-03 12:00',
        'ends_at' => '2026-08-03 13:00',
    ])->assertOk()->assertJsonPath('title', 'Updated title');

    $this->actingAs($user)->deleteJson("/api/v1/events/{$event->id}")
        ->assertNoContent();

    expect(Event::query()->find($event->id))->toBeNull();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/events')->assertUnauthorized();
});

it('does not expose events belonging to another tenant', function (): void {
    $owner = createUser();
    $event = Event::factory()->for($owner)->create();

    $other = createUser();

    $this->actingAs($other)->getJson("/api/v1/events/{$event->id}")
        ->assertNotFound();
});
