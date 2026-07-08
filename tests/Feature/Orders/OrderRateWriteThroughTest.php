<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Pricing\Domain\Models\Rate;

it('records a rates history row when an order with a rate is created', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/orders', [
        'name' => 'Web pre klienta',
        'billing_type' => 'hourly',
        'client_id' => $client->id,
        'rate' => 45,
    ]);

    $response->assertCreated();

    $this->assertDatabaseHas('rates', [
        'user_id' => $user->id,
        'level' => 'order',
        'order_id' => $response->json('id'),
        'rate' => 45,
        'valid_from' => today()->toDateString().' 00:00:00',
    ]);
});

it('appends a new dated row on rate change and keeps history intact', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $orderId = $this->actingAs($user)->postJson('/api/v1/orders', [
        'name' => 'Zákazka',
        'billing_type' => 'hourly',
        'client_id' => $client->id,
        'rate' => 45,
    ])->json('id');

    // Simulate that the original rate has been effective for a month
    Rate::withoutGlobalScopes()->where('order_id', $orderId)->update(['valid_from' => today()->subMonth()]);

    $this->actingAs($user)->putJson("/api/v1/orders/{$orderId}", [
        'name' => 'Zákazka',
        'billing_type' => 'hourly',
        'client_id' => $client->id,
        'rate' => 60,
    ])->assertOk();

    $history = Rate::withoutGlobalScopes()
        ->where('order_id', $orderId)
        ->orderBy('valid_from')
        ->get();

    expect($history)->toHaveCount(2);

    /** @var array{0: Rate, 1: Rate} $rows */
    $rows = $history->all();

    expect((float) $rows[0]->rate)->toBe(45.0)
        ->and((float) $rows[1]->rate)->toBe(60.0)
        ->and($rows[1]->valid_from->toDateString())->toBe(today()->toDateString());
});

it('does not append a history row when the rate is unchanged', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $orderId = $this->actingAs($user)->postJson('/api/v1/orders', [
        'name' => 'Zákazka',
        'billing_type' => 'hourly',
        'client_id' => $client->id,
        'rate' => 45,
    ])->json('id');

    Rate::withoutGlobalScopes()->where('order_id', $orderId)->update(['valid_from' => today()->subMonth()]);

    $this->actingAs($user)->putJson("/api/v1/orders/{$orderId}", [
        'name' => 'Zákazka premenovaná',
        'billing_type' => 'hourly',
        'client_id' => $client->id,
        'rate' => 45,
    ])->assertOk();

    expect(Rate::withoutGlobalScopes()->where('order_id', $orderId)->count())->toBe(1);
});
