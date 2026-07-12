<?php

declare(strict_types=1);

use App\Modules\Orders\Domain\Models\Order;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;

it('starts a timer', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/time-entries/start', [
        'order_id' => $order->id,
        'description' => 'Working',
    ])->assertCreated();

    expect($response->json('is_running'))->toBeTrue()
        ->and($response->json('ended_at'))->toBeNull();
});

it('rejects starting a second timer while one is already running', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);
    TimeEntry::factory()->running()->create(['user_id' => $user->id, 'order_id' => $order->id]);

    $this->actingAs($user)->postJson('/api/v1/time-entries/start', [
        'order_id' => $order->id,
    ])->assertUnprocessable();
});

it('lets different accounts each run their own timer', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);
    TimeEntry::factory()->running()->create(['user_id' => $user->id, 'order_id' => $order->id]);

    $other = createUser();
    $otherOrder = Order::factory()->create(['user_id' => $other->id]);

    $this->actingAs($other)->postJson('/api/v1/time-entries/start', [
        'order_id' => $otherOrder->id,
    ])->assertCreated();
});

it('stops a running timer and computes the duration', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $entry = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'order_id' => $order->id,
        'started_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/time-entries/{$entry->id}/stop")
        ->assertOk();

    expect($response->json('is_running'))->toBeFalse()
        ->and($response->json('ended_at'))->not->toBeNull()
        ->and($response->json('duration_seconds'))->toBeGreaterThanOrEqual(3595);
});

it('rejects stopping a timer that is not running', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $entry = TimeEntry::factory()->create(['user_id' => $user->id, 'order_id' => $order->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/time-entries/{$entry->id}/stop")
        ->assertUnprocessable();
});

it('returns 404 stopping another account\'s time entry', function (): void {
    $owner = createUser();
    $order = Order::factory()->create(['user_id' => $owner->id]);
    $entry = TimeEntry::factory()->running()->create(['user_id' => $owner->id, 'order_id' => $order->id]);

    $other = createUser();

    $this->actingAs($other)
        ->postJson("/api/v1/time-entries/{$entry->id}/stop")
        ->assertNotFound();
});
