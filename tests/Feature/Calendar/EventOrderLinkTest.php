<?php

declare(strict_types=1);

use App\Modules\Calendar\Domain\Models\Event;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Models\Order;

it('creates an event linked to an order and returns the nested order payload', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'name' => 'Website redesign',
        'color' => '#112233',
    ]);

    $response = $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Kickoff call',
        'starts_at' => '2026-08-03 10:00',
        'ends_at' => '2026-08-03 11:00',
        'order_id' => $order->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('order_id', $order->id)
        ->assertJsonPath('order.id', $order->id)
        ->assertJsonPath('order.name', 'Website redesign')
        ->assertJsonPath('order.client_display_name', $client->display_name)
        ->assertJsonPath('effective_color', '#112233');
});

it('returns 404 when order_id belongs to another account', function (): void {
    $user = createUser();
    $stranger = createUser();
    $foreignOrder = Order::factory()->create(['user_id' => $stranger->id]);

    $this->actingAs($user)->postJson('/api/v1/events', [
        'title' => 'Kickoff call',
        'starts_at' => '2026-08-03 10:00',
        'ends_at' => '2026-08-03 11:00',
        'order_id' => $foreignOrder->id,
    ])->assertNotFound();
});

it('unlinks the event instead of deleting it when the order is deleted', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $event = Event::factory()->for($user)->create(['order_id' => $order->id]);

    // Order deletion is a soft delete (the row survives), so unlinking must
    // be explicit — it can't rely on the events.order_id FK's nullOnDelete,
    // which only fires on an actual row DELETE.
    $this->actingAs($user)->deleteJson("/api/v1/orders/{$order->id}")->assertNoContent();

    expect($event->refresh()->order_id)->toBeNull()
        ->and(Event::query()->find($event->id))->not->toBeNull();
});

it('filters the event list by order_id', function (): void {
    $user = createUser();
    $orderA = Order::factory()->create(['user_id' => $user->id]);
    $orderB = Order::factory()->create(['user_id' => $user->id]);

    $eventA = Event::factory()->for($user)->create(['order_id' => $orderA->id]);
    Event::factory()->for($user)->create(['order_id' => $orderB->id]);
    Event::factory()->for($user)->create(['order_id' => null]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/events?order_id={$orderA->id}")
        ->assertOk();

    /** @var list<array<string, mixed>> $data */
    $data = $response->json('data');
    $ids = collect($data)->pluck('id');

    expect($ids)->toHaveCount(1)->toContain($eventA->id);
});

it('falls back to the order color as effective_color only when the event has none', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id, 'color' => '#abcdef']);

    $withOwnColor = Event::factory()->for($user)->create(['order_id' => $order->id, 'color' => '#ff0000']);
    $withoutOwnColor = Event::factory()->for($user)->create(['order_id' => $order->id, 'color' => null]);

    $this->actingAs($user)->getJson("/api/v1/events/{$withOwnColor->id}")
        ->assertOk()
        ->assertJsonPath('data.color', '#ff0000')
        ->assertJsonPath('data.effective_color', '#ff0000');

    $this->actingAs($user)->getJson("/api/v1/events/{$withoutOwnColor->id}")
        ->assertOk()
        ->assertJsonPath('data.color', null)
        ->assertJsonPath('data.effective_color', '#abcdef');
});

it('mixes in virtual order-deadline items within range, read-only and excluded from completed orders', function (): void {
    $user = createUser();

    $inRange = Order::factory()->active()->create([
        'user_id' => $user->id,
        'name' => 'In-range order',
        'deadline' => '2026-08-15',
    ]);
    Order::factory()->active()->create([
        'user_id' => $user->id,
        'name' => 'Out-of-range order',
        'deadline' => '2026-09-15',
    ]);
    Order::factory()->create([
        'user_id' => $user->id,
        'name' => 'Completed order',
        'status' => 'completed',
        'deadline' => '2026-08-16',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/events?from=2026-08-01&to=2026-08-31&include=order_deadlines')
        ->assertOk();

    /** @var list<array<string, mixed>> $data */
    $data = $response->json('data');
    $deadlineItems = collect($data)->where('type', 'order_deadline');

    expect($deadlineItems)->toHaveCount(1);

    /** @var array<string, mixed> $deadlineItem */
    $deadlineItem = $deadlineItems->first();

    expect($deadlineItem['order_id'])->toBe($inRange->id)
        ->and($deadlineItem['id'])->toBeNull();
});

it('does not mix in order-deadline items without the include parameter', function (): void {
    $user = createUser();
    Order::factory()->active()->create([
        'user_id' => $user->id,
        'deadline' => '2026-08-15',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/events?from=2026-08-01&to=2026-08-31')
        ->assertOk();

    expect($response->json('data'))->toBeEmpty();
});

it('excludes virtual order-deadline items from the ICS/CSV export', function (): void {
    $user = createUser();
    Order::factory()->active()->create([
        'user_id' => $user->id,
        'deadline' => '2026-08-15',
    ]);

    $csv = $this->actingAs($user)
        ->get('/api/v1/events/export/csv?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->getContent();

    expect($csv)->not->toContain('order_deadline');
});
