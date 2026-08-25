<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderItem;

/**
 * Eight endpoints across Orders, Clients and Invoicing built their DTO with
 * `SomeData::fromRequest($request)` and nothing else — no
 * `validateAndCreate()`, no `$request->validate()`. The DTOs all carried
 * rules: `rules()` methods, `#[Max]` and `#[Nullable]` attributes, enum
 * types. None of it ran, on paths whose own OpenAPI annotation advertised a
 * 422. What reached the database instead was whatever the request said, so an
 * over-long name was a 500 from Postgres and a nonsense VAT rate was simply
 * stored.
 *
 * `getValidationRules()` rather than `rules()`: the constraints are split
 * between the method and the constructor attributes, and only the merged set
 * is the DTO's actual contract.
 */
it('rejects an order name longer than the column', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->postJson('/api/v1/orders', [
            'name' => str_repeat('a', 300),
            'billing_type' => 'hourly',
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('rejects an unknown billing type instead of failing on the enum', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->postJson('/api/v1/orders', [
            'name' => 'Zákazka',
            'billing_type' => 'barter',
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('billing_type');
});

it('rejects an out-of-range VAT rate when updating an order item', function (): void {
    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);
    $item = OrderItem::factory()->create(['order_id' => $order->id]);

    $this->actingAs($user)
        ->putJson("/api/v1/orders/{$order->id}/items/{$item->id}", [
            'type' => 'service',
            'description' => 'Konzultácia',
            'quantity' => 1,
            'unit' => 'h',
            'unit_price' => 50,
            'vat_rate' => 5000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('vat_rate');
});

it('rejects a malformed contact person e-mail', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/clients/{$client->id}/contact-persons", [
            'name' => 'Jana',
            'surname' => 'Nováková',
            'email' => 'nie-je-email',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('rejects a non-positive manual exchange rate', function (): void {
    $user = createUser();

    $this->actingAs($user)
        ->postJson('/api/v1/exchange-rates', [
            'base_currency' => 'EUR',
            'target_currency' => 'CZK',
            'rate' => 0,
            'date' => '2026-08-19',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rate');
});
