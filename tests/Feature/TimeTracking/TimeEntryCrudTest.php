<?php

declare(strict_types=1);

use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderItem;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;

it('lists only the account\'s own time entries', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);
    TimeEntry::factory()->count(2)->create(['user_id' => $user->id, 'order_id' => $order->id]);

    $other = createUser();
    $otherOrder = Order::factory()->create(['user_id' => $other->id]);
    TimeEntry::factory()->create(['user_id' => $other->id, 'order_id' => $otherOrder->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/time-entries')->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

it('filters by order_id', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $otherOrder = Order::factory()->create(['user_id' => $user->id]);
    TimeEntry::factory()->create(['user_id' => $user->id, 'order_id' => $order->id]);
    TimeEntry::factory()->create(['user_id' => $user->id, 'order_id' => $otherOrder->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/time-entries?order_id='.$order->id)
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

it('creates a time entry', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/time-entries', [
        'order_id' => $order->id,
        'description' => 'Development work',
        'started_at' => '2026-07-01 09:00:00',
        'ended_at' => '2026-07-01 11:00:00',
        'is_billable' => true,
    ])->assertCreated();

    expect($response->json('duration_seconds'))->toBe(7200)
        ->and($response->json('is_invoiced'))->toBeFalse()
        ->and($response->json('source'))->toBe('manual');

    expect(TimeEntry::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('ignores is_invoiced, source and external_id on create', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->postJson('/api/v1/time-entries', [
        'order_id' => $order->id,
        'started_at' => '2026-07-01 09:00:00',
        'is_invoiced' => true,
        'source' => 'clockify',
        'external_id' => 'ext-1',
    ])->assertCreated();

    $entry = TimeEntry::query()->where('user_id', $user->id)->firstOrFail();

    expect($entry->is_invoiced)->toBeFalse()
        ->and($entry->source)->toBe('manual')
        ->and($entry->external_id)->toBeNull();
});

it('returns 404 when creating a time entry against another account\'s order', function (): void {
    $user = createUser();
    $foreignOrder = Order::factory()->create(['user_id' => createUser()->id]);

    $this->actingAs($user)->postJson('/api/v1/time-entries', [
        'order_id' => $foreignOrder->id,
        'started_at' => '2026-07-01 09:00:00',
    ])->assertNotFound();
});

it('returns 404 when viewing another account\'s time entry', function (): void {
    $owner = createUser();
    $order = Order::factory()->create(['user_id' => $owner->id]);
    $entry = TimeEntry::factory()->create(['user_id' => $owner->id, 'order_id' => $order->id]);

    $other = createUser();

    $this->actingAs($other)->getJson("/api/v1/time-entries/{$entry->id}")->assertNotFound();
});

it('rejects an order item that does not belong to the given order', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $otherOrder = Order::factory()->create(['user_id' => $user->id]);
    $foreignItem = OrderItem::factory()->create(['order_id' => $otherOrder->id]);

    $this->actingAs($user)->postJson('/api/v1/time-entries', [
        'order_id' => $order->id,
        'order_item_id' => $foreignItem->id,
        'started_at' => '2026-07-01 09:00:00',
    ])->assertUnprocessable();
});

it('updates a time entry', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $entry = TimeEntry::factory()->create(['user_id' => $user->id, 'order_id' => $order->id]);

    $response = $this->actingAs($user)->putJson("/api/v1/time-entries/{$entry->id}", [
        'order_id' => $order->id,
        'description' => 'Updated description',
        'started_at' => '2026-07-01 09:00:00',
        'ended_at' => '2026-07-01 10:00:00',
    ])->assertOk();

    expect($response->json('description'))->toBe('Updated description');
});

it('rejects updating an already invoiced time entry', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $entry = TimeEntry::factory()->invoiced()->create(['user_id' => $user->id, 'order_id' => $order->id]);

    $this->actingAs($user)->putJson("/api/v1/time-entries/{$entry->id}", [
        'order_id' => $order->id,
        'started_at' => '2026-07-01 09:00:00',
    ])->assertUnprocessable();
});

it('deletes a time entry', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $entry = TimeEntry::factory()->create(['user_id' => $user->id, 'order_id' => $order->id]);

    $this->actingAs($user)->deleteJson("/api/v1/time-entries/{$entry->id}")->assertNoContent();

    expect(TimeEntry::query()->find($entry->id))->toBeNull();
});

it('rejects deleting an already invoiced time entry', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $entry = TimeEntry::factory()->invoiced()->create(['user_id' => $user->id, 'order_id' => $order->id]);

    $this->actingAs($user)->deleteJson("/api/v1/time-entries/{$entry->id}")->assertUnprocessable();

    expect(TimeEntry::withoutGlobalScope('user')->find($entry->id))->not->toBeNull();
});
