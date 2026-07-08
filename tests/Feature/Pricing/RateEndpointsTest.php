<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Domain\Models\Rate;

it('creates a global user-level rate', function (): void {
    $user = createUser(['default_currency' => 'EUR']);

    $response = $this->actingAs($user)->postJson('/api/v1/rates', [
        'level' => 'user',
        'rate' => 42.5,
    ]);

    $response->assertCreated()
        ->assertJsonPath('level', 'user')
        ->assertJsonPath('rate', 42.5)
        ->assertJsonPath('valid_from', today()->toDateString());

    $this->assertDatabaseHas('rates', [
        'user_id' => $user->id,
        'level' => 'user',
        'rate' => 42.5,
    ]);
});

it('creates a client-level rate with explicit validity date', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/rates', [
        'level' => 'client',
        'client_id' => $client->id,
        'rate' => 55,
        'valid_from' => today()->addDays(5)->toDateString(),
    ]);

    $response->assertCreated()
        ->assertJsonPath('client_id', $client->id)
        ->assertJsonPath('is_deletable', true);
});

it('rejects a client-level rate without client_id', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/rates', [
        'level' => 'client',
        'rate' => 55,
    ])->assertStatus(422);
});

it('rejects a rate for another user\'s order', function (): void {
    $user = createUser();
    $otherUser = createUser();
    $otherOrder = Order::factory()->create([
        'user_id' => $otherUser->id,
        'client_id' => Client::factory()->create(['user_id' => $otherUser->id])->id,
    ]);

    $this->actingAs($user)->postJson('/api/v1/rates', [
        'level' => 'order',
        'order_id' => $otherOrder->id,
        'rate' => 55,
    ])->assertStatus(422);
});

it('rejects a rate whose currency differs from the scope currency', function (): void {
    $user = createUser(['default_currency' => 'EUR']);

    $this->actingAs($user)->postJson('/api/v1/rates', [
        'level' => 'user',
        'rate' => 1000,
        'currency' => 'CZK',
    ])->assertStatus(422);
});

it('deletes a rate valid from today or later, refuses a historical one', function (): void {
    $user = createUser();

    $future = Rate::factory()->create(['user_id' => $user->id, 'level' => 'user', 'rate' => 50, 'valid_from' => today()->addDay()]);
    $past = Rate::factory()->create(['user_id' => $user->id, 'level' => 'user', 'rate' => 40, 'valid_from' => today()->subDay()]);

    $this->actingAs($user)->deleteJson("/api/v1/rates/{$future->id}")->assertNoContent();
    $this->actingAs($user)->deleteJson("/api/v1/rates/{$past->id}")->assertStatus(422);

    $this->assertDatabaseMissing('rates', ['id' => $future->id]);
    $this->assertDatabaseHas('rates', ['id' => $past->id]);
});

it('resolves the effective rate with level information', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    Rate::factory()->create(['user_id' => $user->id, 'level' => 'user', 'rate' => 40, 'valid_from' => today()->subDays(30)]);
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'client', 'client_id' => $client->id, 'rate' => 52, 'valid_from' => today()->subDays(30)]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/rates/effective?client_id={$client->id}")
        ->assertOk()
        ->assertJsonPath('data.level', 'client');

    expect((float) $response->json('data.rate'))->toBe(52.0);
});

it('isolates rate history between users', function (): void {
    $userA = createUser();
    $userB = createUser();

    Rate::factory()->create(['user_id' => $userA->id, 'level' => 'user', 'rate' => 40, 'valid_from' => today()]);

    $this->actingAs($userB)
        ->getJson('/api/v1/rates')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
