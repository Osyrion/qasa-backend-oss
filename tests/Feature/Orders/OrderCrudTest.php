<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderItem;

it('creates a billable order for a client', function (): void {
    $user = createUser();
    $client = Client::factory()->for($user, 'user')->create();

    $this->actingAs($user)
        ->postJson('/api/v1/orders', [
            'name' => 'Web redesign',
            'billing_type' => 'hourly',
            'client_id' => $client->id,
            'rate' => 50,
            'currency' => 'EUR',
        ])
        ->assertCreated()
        ->assertJsonPath('name', 'Web redesign')
        ->assertJsonPath('billing_type', 'hourly');

    expect(Order::query()->first())
        ->user_id->toBe($user->id)
        ->client_id->toBe($client->id);
});

it('creates a personal order without a client or rate', function (): void {
    $this->actingAs(createUser())
        ->postJson('/api/v1/orders', [
            'name' => 'Vlastný projekt',
            'billing_type' => 'hourly',
        ])
        ->assertCreated()
        ->assertJsonPath('client_id', null);
});

it('rejects a personal order with a rate', function (): void {
    $this->actingAs(createUser())
        ->postJson('/api/v1/orders', [
            'name' => 'Vlastný projekt',
            'billing_type' => 'hourly',
            'rate' => 50,
        ])
        ->assertStatus(422);

    expect(Order::query()->count())->toBe(0);
});

it('rejects a billable order with a rate-based billing type but no rate', function (): void {
    $user = createUser();
    $client = Client::factory()->for($user, 'user')->create();

    $this->actingAs($user)
        ->postJson('/api/v1/orders', [
            'name' => 'Web redesign',
            'billing_type' => 'hourly',
            'client_id' => $client->id,
        ])
        ->assertStatus(422);
});

it('lists only the authenticated account\'s orders and filters by status', function (): void {
    $user = createUser();
    Order::factory()->active()->create(['user_id' => $user->id]);
    Order::factory()->create(['user_id' => $user->id, 'status' => 'paused']);
    Order::factory()->create(['user_id' => createUser()->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($user)
        ->getJson('/api/v1/orders?status=paused')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('shows an order with its relations', function (): void {
    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);
    OrderItem::factory()->create(['order_id' => $order->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('id', $order->id)
        ->assertJsonCount(1, 'items');
});

it('hides another account\'s order entirely', function (): void {
    $victimOrder = Order::factory()->active()->create(['user_id' => createUser()->id]);
    $attacker = createUser();

    $this->actingAs($attacker)
        ->getJson("/api/v1/orders/{$victimOrder->id}")
        ->assertNotFound();

    $this->actingAs($attacker)
        ->putJson("/api/v1/orders/{$victimOrder->id}", [
            'name' => 'Hijacked',
            'billing_type' => 'hourly',
        ])
        ->assertNotFound();

    $this->actingAs($attacker)
        ->deleteJson("/api/v1/orders/{$victimOrder->id}")
        ->assertNotFound();
});

it('updates an order', function (): void {
    $user = createUser();
    $order = Order::factory()->active()->create([
        'user_id' => $user->id,
        'client_id' => null,
        'rate' => null,
        'name' => 'Old name',
    ]);

    $this->actingAs($user)
        ->putJson("/api/v1/orders/{$order->id}", [
            'name' => 'New name',
            'billing_type' => 'hourly',
            'status' => 'paused',
        ])
        ->assertOk()
        ->assertJsonPath('name', 'New name')
        ->assertJsonPath('status', 'paused');
});

it('rejects updating a completed order', function (): void {
    $user = createUser();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'completed',
    ]);

    $this->actingAs($user)
        ->putJson("/api/v1/orders/{$order->id}", [
            'name' => 'New name',
            'billing_type' => 'hourly',
        ])
        ->assertForbidden();
});

it('soft deletes an order without invoiced items', function (): void {
    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/orders/{$order->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('orders', ['id' => $order->id]);
});

it('rejects deleting an order whose items were already invoiced', function (): void {
    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);
    $item = OrderItem::factory()->create(['order_id' => $order->id]);
    InvoiceItem::factory()->create(['order_item_id' => $item->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/orders/{$order->id}")
        ->assertStatus(422);

    $this->assertDatabaseHas('orders', ['id' => $order->id, 'deleted_at' => null]);
});
