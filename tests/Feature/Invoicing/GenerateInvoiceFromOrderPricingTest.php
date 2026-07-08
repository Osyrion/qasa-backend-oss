<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderItem;
use App\Modules\Pricing\Domain\Models\Rate;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
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

function logHours(User $user, Order $order, int $daysAgo, ?float $rateOverride = null): TimeEntry
{
    $start = now()->subDays($daysAgo)->setTime(9, 0);

    return TimeEntry::factory()->create([
        'user_id' => $user->id,
        'order_id' => $order->id,
        'started_at' => $start,
        'ended_at' => $start->copy()->addHours(2),
        'duration_seconds' => 7200,
        'rate_override' => $rateOverride,
        'vat_rate' => 20,
        'is_billable' => true,
        'is_invoiced' => false,
    ]);
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

it('prices unbilled hours with the rate valid on the work date, not the current one', function (): void {
    [$user, $client, $order] = billingScope();

    // Client rate was 40 when the work happened…
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'client', 'client_id' => $client->id, 'rate' => 40, 'valid_from' => today()->subDays(100)]);
    logHours($user, $order, daysAgo: 30);

    // …and was raised to 50 yesterday.
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'client', 'client_id' => $client->id, 'rate' => 50, 'valid_from' => today()->subDay()]);

    $response = generateInvoice($this, $user, $client, $order);

    $response->assertCreated();
    expect((float) $response->json('items.0.unit_price'))->toBe(40.0)
        ->and((float) $response->json('items.0.quantity'))->toBe(2.0);
});

it('prices work done after a rate change with the new rate', function (): void {
    [$user, $client, $order] = billingScope();

    Rate::factory()->create(['user_id' => $user->id, 'level' => 'client', 'client_id' => $client->id, 'rate' => 40, 'valid_from' => today()->subDays(100)]);
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'client', 'client_id' => $client->id, 'rate' => 50, 'valid_from' => today()->subDays(5)]);

    logHours($user, $order, daysAgo: 30); // old rate period
    logHours($user, $order, daysAgo: 1);  // new rate period

    $response = generateInvoice($this, $user, $client, $order);

    /** @var array<int, array<string, mixed>> $items */
    $items = $response->json('items');
    $prices = collect($items)->map(fn (array $i): float => (float) $i['unit_price'])->sort()->values();
    expect($prices->all())->toBe([40.0, 50.0]);
});

it('does not change an already generated invoice when the rate changes later', function (): void {
    [$user, $client, $order] = billingScope();

    Rate::factory()->create(['user_id' => $user->id, 'level' => 'client', 'client_id' => $client->id, 'rate' => 40, 'valid_from' => today()->subDays(100)]);
    logHours($user, $order, daysAgo: 30);

    $response = generateInvoice($this, $user, $client, $order);
    $response->assertCreated();
    $invoiceId = $response->json('id');
    $total = $response->json('total');

    // Rate raised after invoicing — the snapshot must not move.
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'client', 'client_id' => $client->id, 'rate' => 80, 'valid_from' => today()]);

    $fresh = $this->actingAs($user)->getJson("/api/v1/invoices/{$invoiceId}")->assertOk();
    expect($fresh->json('data.total'))->toBe($total);
    expect((float) $fresh->json('data.items.0.unit_price'))->toBe(40.0);
});

it('lets rate_override on a time entry beat every level', function (): void {
    [$user, $client, $order] = billingScope();

    Rate::factory()->create(['user_id' => $user->id, 'level' => 'order', 'order_id' => $order->id, 'rate' => 55, 'valid_from' => today()->subDays(100)]);
    logHours($user, $order, daysAgo: 10, rateOverride: 99.0);

    $response = generateInvoice($this, $user, $client, $order);

    expect((float) $response->json('items.0.unit_price'))->toBe(99.0);
});

it('prefers the order rate over client and global rates', function (): void {
    [$user, $client, $order] = billingScope();
    $validFrom = today()->subDays(100);

    Rate::factory()->create(['user_id' => $user->id, 'level' => 'user', 'rate' => 40, 'valid_from' => $validFrom]);
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'client', 'client_id' => $client->id, 'rate' => 45, 'valid_from' => $validFrom]);
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'order', 'order_id' => $order->id, 'rate' => 55, 'valid_from' => $validFrom]);

    logHours($user, $order, daysAgo: 10);

    $response = generateInvoice($this, $user, $client, $order);

    expect((float) $response->json('items.0.unit_price'))->toBe(55.0);
});

it('falls back to the global user rate when client and order have none', function (): void {
    [$user, $client, $order] = billingScope();

    Rate::factory()->create(['user_id' => $user->id, 'level' => 'user', 'rate' => 38, 'valid_from' => today()->subDays(100)]);
    logHours($user, $order, daysAgo: 10);

    $response = generateInvoice($this, $user, $client, $order);

    expect((float) $response->json('items.0.unit_price'))->toBe(38.0);
});

it('prices at zero when no rate exists anywhere', function (): void {
    [$user, $client, $order] = billingScope();
    logHours($user, $order, daysAgo: 10);

    $response = generateInvoice($this, $user, $client, $order);

    expect((float) $response->json('items.0.unit_price'))->toBe(0.0);
});

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
