<?php

declare(strict_types=1);

use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderItem;

it('adds an item to an order', function (): void {
    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/orders/{$order->id}/items", [
            'type' => 'service',
            'description' => 'Konzultácia',
            'quantity' => 2,
            'unit' => 'h',
            'unit_price' => 50,
            'vat_rate' => 20,
        ])
        ->assertCreated()
        ->assertJsonPath('description', 'Konzultácia');

    $this->assertDatabaseHas('order_items', [
        'order_id' => $order->id,
        'description' => 'Konzultácia',
    ]);
});

it('lists the items of an order', function (): void {
    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);
    OrderItem::factory()->count(2)->create(['order_id' => $order->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/orders/{$order->id}/items")
        ->assertOk()
        ->assertJsonCount(2);
});

it('updates an item on an order', function (): void {
    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);
    $item = OrderItem::factory()->create([
        'order_id' => $order->id,
        'description' => 'Original',
    ]);

    $this->actingAs($user)
        ->putJson("/api/v1/orders/{$order->id}/items/{$item->id}", [
            'type' => $item->type,
            'description' => 'Upravená položka',
            'quantity' => 3,
            'unit' => $item->unit,
            'unit_price' => 75,
            'vat_rate' => 20,
            'sort_order' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('description', 'Upravená položka');

    expect($item->refresh()->description)->toBe('Upravená položka');
});
