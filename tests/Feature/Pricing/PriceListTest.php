<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Pricing\Domain\Models\PriceList;
use App\Modules\Pricing\Domain\Models\PriceListItem;

it('creates a price list segmented by currency and country', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/price-lists', [
        'name' => 'Cenník SK',
        'currency' => 'EUR',
        'country' => 'sk',
    ])
        ->assertCreated()
        ->assertJsonPath('name', 'Cenník SK')
        ->assertJsonPath('currency', 'EUR')
        ->assertJsonPath('country', 'SK');
});

it('keeps only one default price list per user', function (): void {
    $user = createUser();
    $first = PriceList::factory()->default()->create(['user_id' => $user->id]);

    $this->actingAs($user)->postJson('/api/v1/price-lists', [
        'name' => 'Nový default',
        'is_default' => true,
    ])->assertCreated();

    expect($first->refresh()->is_default)->toBeFalse();
});

it('manages price list items including custom units', function (): void {
    $user = createUser();
    $priceList = PriceList::factory()->create(['user_id' => $user->id]);

    $created = $this->actingAs($user)->postJson("/api/v1/price-lists/{$priceList->id}/items", [
        'name' => 'Balné',
        'unit' => 'balenie',
        'unit_price' => 12.5,
        'vat_rate' => 20,
    ]);

    $created->assertCreated()->assertJsonPath('unit', 'balenie');
    expect((float) $created->json('unit_price'))->toBe(12.5);

    $itemId = $created->json('id');

    $updated = $this->actingAs($user)->putJson("/api/v1/price-lists/{$priceList->id}/items/{$itemId}", [
        'name' => 'Balné',
        'unit' => 'ks',
        'unit_price' => 15,
        'vat_rate' => 20,
    ]);

    $updated->assertOk();
    expect((float) $updated->json('unit_price'))->toBe(15.0);

    $this->actingAs($user)->deleteJson("/api/v1/price-lists/{$priceList->id}/items/{$itemId}")
        ->assertNoContent();
});

it('hides other users\' price lists', function (): void {
    $userA = createUser();
    $userB = createUser();
    $list = PriceList::factory()->create(['user_id' => $userA->id]);

    $this->actingAs($userB)->getJson("/api/v1/price-lists/{$list->id}")->assertNotFound();
    $this->actingAs($userB)->getJson('/api/v1/price-lists')->assertOk()->assertJsonCount(0, 'data');
});

it('keeps invoice items intact when a price list item is deleted', function (): void {
    $user = createUser();
    $priceList = PriceList::factory()->create(['user_id' => $user->id]);
    $item = PriceListItem::factory()->create(['price_list_id' => $priceList->id, 'unit_price' => 99]);

    $invoiceItem = InvoiceItem::factory()->create([
        'price_list_item_id' => $item->id,
        'unit_price' => 99,
    ]);

    $item->delete();

    $fresh = $invoiceItem->refresh();
    expect($fresh->price_list_item_id)->toBeNull()
        ->and((float) $fresh->unit_price)->toBe(99.0);
});
