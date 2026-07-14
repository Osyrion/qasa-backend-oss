<?php

declare(strict_types=1);

use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderAttachment;
use App\Modules\Orders\Domain\Models\OrderItem;

it('does not let a user delete an item belonging to another account order', function (): void {
    $victim = createUser();
    $attacker = createUser();

    $victimOrder = Order::factory()->create(['user_id' => $victim->id]);
    $victimItem = OrderItem::factory()->create(['order_id' => $victimOrder->id]);

    $attackerOrder = Order::factory()->create(['user_id' => $attacker->id]);

    // Attacker authorizes on their own order but references the victim's item.
    $this->actingAs($attacker)
        ->deleteJson("/api/v1/orders/{$attackerOrder->id}/items/{$victimItem->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('order_items', ['id' => $victimItem->id]);
});

it('does not let a user update an item belonging to another account order', function (): void {
    $victim = createUser();
    $attacker = createUser();

    $victimOrder = Order::factory()->create(['user_id' => $victim->id]);
    $victimItem = OrderItem::factory()->create([
        'order_id' => $victimOrder->id,
        'description' => 'Original',
    ]);

    $attackerOrder = Order::factory()->create(['user_id' => $attacker->id]);

    $this->actingAs($attacker)
        ->putJson("/api/v1/orders/{$attackerOrder->id}/items/{$victimItem->id}", [
            'type' => $victimItem->type,
            'description' => 'Hijacked',
            'quantity' => 1,
            'unit' => $victimItem->unit,
            'unit_price' => 1,
            'vat_rate' => 0,
            'sort_order' => 0,
        ])
        ->assertNotFound();

    expect($victimItem->refresh()->description)->toBe('Original');
});

it('does not let a user delete an attachment belonging to another account order', function (): void {
    $victim = createUser();
    $attacker = createUser();

    $victimOrder = Order::factory()->create(['user_id' => $victim->id]);
    $victimAttachment = OrderAttachment::factory()->create([
        'order_id' => $victimOrder->id,
        'user_id' => $victim->id,
    ]);

    $attackerOrder = Order::factory()->create(['user_id' => $attacker->id]);

    $this->actingAs($attacker)
        ->deleteJson("/api/v1/orders/{$attackerOrder->id}/attachments/{$victimAttachment->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('order_attachments', ['id' => $victimAttachment->id]);
});

it('still lets a user delete an item on their own order', function (): void {
    $user = createUser();

    // Pin an editable status — the factory default is random and the update
    // policy rejects completed/cancelled orders.
    $order = Order::factory()->active()->create(['user_id' => $user->id]);
    $item = OrderItem::factory()->create(['order_id' => $order->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/orders/{$order->id}/items/{$item->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('order_items', ['id' => $item->id]);
});
