<?php

declare(strict_types=1);

it('rejects an event shorter than one slot', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Too short',
        'starts_at' => '2026-08-03 10:00',
        'ends_at' => '2026-08-03 10:14',
    ])->assertUnprocessable();
});

it('rejects times not aligned to the slot grid', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Misaligned start',
        'starts_at' => '2026-08-03 10:07',
        'ends_at' => '2026-08-03 11:00',
    ])->assertUnprocessable();

    $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Misaligned end',
        'starts_at' => '2026-08-03 10:00',
        'ends_at' => '2026-08-03 10:52',
    ])->assertUnprocessable();

    $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Non-zero seconds',
        'starts_at' => '2026-08-03 10:00:05',
        'ends_at' => '2026-08-03 11:00',
    ])->assertUnprocessable();
});

it('rejects an event that crosses midnight', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Overnight',
        'starts_at' => '2026-08-03 22:00',
        'ends_at' => '2026-08-04 01:00',
    ])->assertUnprocessable();
});

it('rejects an event ending before or at its start', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Backwards',
        'starts_at' => '2026-08-03 11:00',
        'ends_at' => '2026-08-03 10:00',
    ])->assertUnprocessable();
});

it('rejects an invalid color', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Bad color',
        'starts_at' => '2026-08-03 10:00',
        'ends_at' => '2026-08-03 11:00',
        'color' => 'red',
    ])->assertUnprocessable();

    $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Bad color hex',
        'starts_at' => '2026-08-03 10:00',
        'ends_at' => '2026-08-03 11:00',
        'color' => '#GGGGGG',
    ])->assertUnprocessable();
});

it('normalizes an all-day event to midnight-to-midnight even without ends_at', function (): void {
    $user = createUser();

    $response = $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Company holiday',
        'starts_at' => '2026-08-03 15:37',
        'is_all_day' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('is_all_day', true)
        ->assertJsonPath('starts_at', fn (string $value): bool => str_starts_with($value, '2026-08-03T00:00:00'))
        ->assertJsonPath('ends_at', fn (string $value): bool => str_starts_with($value, '2026-08-04T00:00:00'));
});
