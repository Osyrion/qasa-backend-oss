<?php

declare(strict_types=1);

use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderNote;

it('creates and lists notes on an order', function (): void {
    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/orders/{$order->id}/notes", [
            'content' => 'Klient potvrdil rozsah.',
        ])
        ->assertCreated()
        ->assertJsonPath('content', 'Klient potvrdil rozsah.');

    $this->actingAs($user)
        ->getJson("/api/v1/orders/{$order->id}/notes")
        ->assertOk()
        ->assertJsonCount(1);
});

it('lets the author delete their own note', function (): void {
    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);
    $note = OrderNote::factory()->create([
        'order_id' => $order->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/orders/{$order->id}/notes/{$note->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('order_notes', ['id' => $note->id]);
});
