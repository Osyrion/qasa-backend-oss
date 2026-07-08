<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Application\Services\RateResolver;
use App\Modules\Pricing\Domain\Enums\RateLevel;
use App\Modules\Pricing\Domain\Models\Rate;

/** @return array{0: User, 1: Client, 2: Order} */
function makeScope(): array
{
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'billing_type' => 'hourly',
        'rate' => null,
    ]);

    return [$user, $client, $order];
}

it('resolves a user-level global rate', function (): void {
    [$user, $client, $order] = makeScope();

    Rate::factory()->create([
        'user_id' => $user->id,
        'level' => RateLevel::User->value,
        'rate' => 40,
        'valid_from' => today()->subMonths(6),
    ]);

    $resolution = app(RateResolver::class)->resolve($user, $client, $order, today());

    expect($resolution)->not->toBeNull()
        ->and($resolution?->rate)->toBe(40.0)
        ->and($resolution?->level)->toBe(RateLevel::User);
});

it('prefers the most specific level: order > client > user', function (): void {
    [$user, $client, $order] = makeScope();
    $validFrom = today()->subMonths(6);

    Rate::factory()->create(['user_id' => $user->id, 'level' => 'user', 'rate' => 40, 'valid_from' => $validFrom]);
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'client', 'client_id' => $client->id, 'rate' => 45, 'valid_from' => $validFrom]);
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'order', 'order_id' => $order->id, 'rate' => 55, 'valid_from' => $validFrom]);

    $resolver = app(RateResolver::class);

    expect($resolver->resolve($user, $client, $order, today())?->rate)->toBe(55.0)
        ->and($resolver->resolve($user, $client, null, today())?->rate)->toBe(45.0)
        ->and($resolver->resolve($user, null, null, today())?->rate)->toBe(40.0);
});

it('applies the rate valid on the work date, not the newest one', function (): void {
    [$user, $client, $order] = makeScope();

    Rate::factory()->create(['user_id' => $user->id, 'level' => 'user', 'rate' => 40, 'valid_from' => today()->subDays(100)]);
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'user', 'rate' => 50, 'valid_from' => today()->subDay()]);

    $resolver = app(RateResolver::class);

    expect($resolver->resolve($user, $client, $order, today()->subDays(30))?->rate)->toBe(40.0)
        ->and($resolver->resolve($user, $client, $order, today())?->rate)->toBe(50.0);
});

it('ignores rates that only become valid in the future', function (): void {
    [$user, $client, $order] = makeScope();

    Rate::factory()->create(['user_id' => $user->id, 'level' => 'user', 'rate' => 40, 'valid_from' => today()->subDays(10)]);
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'user', 'rate' => 99, 'valid_from' => today()->addDays(10)]);

    expect(app(RateResolver::class)->resolve($user, $client, $order, today())?->rate)->toBe(40.0);
});

it('returns null when no rate applies', function (): void {
    [$user, $client, $order] = makeScope();

    expect(app(RateResolver::class)->resolve($user, $client, $order, today()))->toBeNull();
});

it('falls through a tombstone to the broader level', function (): void {
    [$user, $client, $order] = makeScope();

    Rate::factory()->create(['user_id' => $user->id, 'level' => 'client', 'client_id' => $client->id, 'rate' => 45, 'valid_from' => today()->subDays(100)]);
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'order', 'order_id' => $order->id, 'rate' => 60, 'valid_from' => today()->subDays(100)]);
    // Order rate removed 10 days ago — tombstone row
    Rate::factory()->create(['user_id' => $user->id, 'level' => 'order', 'order_id' => $order->id, 'rate' => null, 'valid_from' => today()->subDays(10)]);

    $resolver = app(RateResolver::class);

    expect($resolver->resolve($user, $client, $order, today()->subDays(30))?->rate)->toBe(60.0)
        ->and($resolver->resolve($user, $client, $order, today())?->rate)->toBe(45.0);
});

it('ignores rates of other clients and orders in the same scope query', function (): void {
    [$user, $client, $order] = makeScope();
    $otherClient = Client::factory()->create(['user_id' => $user->id]);

    Rate::factory()->create(['user_id' => $user->id, 'level' => 'client', 'client_id' => $otherClient->id, 'rate' => 77, 'valid_from' => today()->subDays(10)]);

    expect(app(RateResolver::class)->resolve($user, $client, $order, today()))->toBeNull();
});

it('resolves without an authenticated user (global scope bypass)', function (): void {
    [$user, $client, $order] = makeScope();

    Rate::factory()->create(['user_id' => $user->id, 'level' => 'user', 'rate' => 40, 'valid_from' => today()->subDay()]);

    expect(auth()->check())->toBeFalse()
        ->and(app(RateResolver::class)->resolve($user, $client, $order, today())?->rate)->toBe(40.0);
});
