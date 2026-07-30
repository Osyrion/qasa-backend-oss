<?php

declare(strict_types=1);

use App\Modules\Orders\Domain\Enums\OrderStatus;
use App\Modules\Orders\Domain\Models\Order;

/**
 * @return list<string>
 */
function orderSearchIds(string $query): array
{
    /** @var list<string> */
    return test()->getJson('/api/v1/orders?'.$query)
        ->assertOk()
        ->json('data.*.id');
}

it('matches order names without diacritics in either direction', function (string $term): void {
    $user = createUser();
    $match = Order::factory()->create(['user_id' => $user->id, 'name' => 'Rekonštrukcia strechy']);
    Order::factory()->create(['user_id' => $user->id, 'name' => 'Návrh loga']);

    $this->actingAs($user);

    expect(orderSearchIds('search='.urlencode($term)))->toBe([$match->id]);
})->with([
    'unaccented needle, accented data' => 'rekonstrukcia',
    'accented needle' => 'Rekonštrukcia',
    'mixed case' => 'STRECHY',
    'accented needle, unaccented data' => 'stréchy',
]);

it('treats % in the needle as a literal', function (): void {
    $user = createUser();
    $match = Order::factory()->create(['user_id' => $user->id, 'name' => 'Zľava 20% pre klienta']);
    Order::factory()->create(['user_id' => $user->id, 'name' => 'Bežná zákazka']);

    $this->actingAs($user);

    expect(orderSearchIds('search='.urlencode('20%')))->toBe([$match->id]);
});

it('does not let the search escape the other filters', function (): void {
    $user = createUser();
    Order::factory()->create([
        'user_id' => $user->id,
        'name' => 'Rekonštrukcia strechy',
        'status' => OrderStatus::Completed->value,
    ]);
    $active = Order::factory()->create([
        'user_id' => $user->id,
        'name' => 'Rekonštrukcia fasády',
        'status' => OrderStatus::Active->value,
    ]);

    $this->actingAs($user);

    // Both names match the needle; only the active one may come back.
    expect(orderSearchIds('status=active&search=rekonstrukcia'))->toBe([$active->id]);
});
