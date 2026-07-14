<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\VatRate;

it('creates, lists, updates and deletes VAT rates', function (): void {
    $user = createUser();

    $create = $this->actingAs($user)->postJson('/api/v1/vat-rates', [
        'code' => 'SK-23',
        'country' => 'SK',
        'rate' => 23,
        'is_default' => true,
    ]);

    $create->assertCreated();
    $id = $create->json('id');

    $this->actingAs($user)->getJson('/api/v1/vat-rates')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($user)->putJson("/api/v1/vat-rates/{$id}", [
        'code' => 'SK-23',
        'country' => 'SK',
        'rate' => 23,
        'label' => 'Základná sadzba',
        'is_default' => true,
    ])->assertOk()->assertJsonPath('label', 'Základná sadzba');

    $this->actingAs($user)->deleteJson("/api/v1/vat-rates/{$id}")->assertNoContent();

    expect(VatRate::withoutGlobalScope('user')->count())->toBe(0);
});

it('hides other users VAT rates', function (): void {
    $owner = createUser();
    $rate = VatRate::factory()->create(['user_id' => $owner->id]);

    $intruder = createUser();

    $this->actingAs($intruder)->getJson("/api/v1/vat-rates/{$rate->id}")->assertNotFound();
    $this->actingAs($intruder)->getJson('/api/v1/vat-rates')->assertOk()->assertJsonCount(0, 'data');
});

it('keeps a single default per user and country', function (): void {
    $user = createUser();

    $first = VatRate::factory()->create([
        'user_id' => $user->id, 'country' => 'SK', 'code' => 'SK-23', 'rate' => 23, 'is_default' => true,
    ]);

    $this->actingAs($user)->postJson('/api/v1/vat-rates', [
        'code' => 'SK-5',
        'country' => 'SK',
        'rate' => 5,
        'is_default' => true,
    ])->assertCreated();

    expect($first->refresh()->is_default)->toBeFalse()
        ->and(VatRate::withoutGlobalScope('user')->where('is_default', true)->count())->toBe(1);
});

it('allows the same code for two different tenants', function (): void {
    $a = createUser();
    $b = createUser();

    $this->actingAs($a)->postJson('/api/v1/vat-rates', [
        'code' => 'SK-23', 'country' => 'SK', 'rate' => 23,
    ])->assertCreated();

    $this->actingAs($b)->postJson('/api/v1/vat-rates', [
        'code' => 'SK-23', 'country' => 'SK', 'rate' => 23,
    ])->assertCreated();

    expect(VatRate::withoutGlobalScope('user')->where('code', 'SK-23')->count())->toBe(2);
});

it('rejects a duplicate code for the same tenant', function (): void {
    $user = createUser();
    VatRate::factory()->create(['user_id' => $user->id, 'code' => 'SK-23']);

    $this->actingAs($user)->postJson('/api/v1/vat-rates', [
        'code' => 'SK-23', 'country' => 'SK', 'rate' => 23,
    ])->assertUnprocessable()->assertJsonValidationErrors('code');
});
