<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderItem;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/** @return array{0: User, 1: Client, 2: Order} */
function billingScope(): array
{
    $user = createUser(['default_currency' => 'EUR']);
    $client = Client::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'billing_type' => 'hourly',
        'rate' => null,
        'currency' => null,
        'status' => 'active',
    ]);

    return [$user, $client, $order];
}

/** @return TestResponse<Response> */
function generateInvoice(TestCase $test, User $user, Client $client, Order $order): TestResponse
{
    return $test->actingAs($user)->postJson('/api/v1/invoices/generate-from-order', [
        'order_id' => $order->id,
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ]);
}

it('snapshots order items verbatim including custom units (free-text billing)', function (): void {
    [$user, $client, $order] = billingScope();

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'type' => 'product',
        'description' => 'Kartónové krabice',
        'quantity' => 3,
        'unit' => 'balenie',
        'unit_price' => 12.5,
        'vat_rate' => 20,
    ]);

    $response = generateInvoice($this, $user, $client, $order);

    $response->assertCreated();
    expect($response->json('items.0.unit'))->toBe('balenie')
        ->and((float) $response->json('items.0.unit_price'))->toBe(12.5)
        ->and((float) $response->json('items.0.quantity'))->toBe(3.0);
});

it('rejects generating from an order with no items', function (): void {
    [$user, $client, $order] = billingScope();

    generateInvoice($this, $user, $client, $order)->assertUnprocessable();
});
