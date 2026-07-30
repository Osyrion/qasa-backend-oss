<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\VatRate;
use App\Modules\Invoicing\Domain\Rules\VatRateInCatalog;
use Illuminate\Support\Facades\Validator;

function passesVatRateInCatalog(VatRateInCatalog $rule, float $rate): bool
{
    return Validator::make(['vat_rate' => $rate], ['vat_rate' => [$rule]])->passes();
}

it('always allows a zero rate', function (): void {
    $rule = new VatRateInCatalog('some-user-id', 'SK');

    expect(passesVatRateInCatalog($rule, 0))->toBeTrue();
});

it('passes when the rate matches a catalog entry valid on the date', function (): void {
    $user = createUser();
    VatRate::factory()->create(['user_id' => $user->id, 'country' => 'SK', 'code' => 'SK-23', 'rate' => 23]);

    $rule = new VatRateInCatalog($user->id, 'SK');

    expect(passesVatRateInCatalog($rule, 23))->toBeTrue();
});

it('fails when the rate is not in the catalog', function (): void {
    $user = createUser();
    VatRate::factory()->create(['user_id' => $user->id, 'country' => 'SK', 'code' => 'SK-23', 'rate' => 23]);

    $rule = new VatRateInCatalog($user->id, 'SK');

    expect(passesVatRateInCatalog($rule, 10))->toBeFalse();
});

it('fails when the matching rate has expired before the given date', function (): void {
    $user = createUser();
    VatRate::factory()->create([
        'user_id' => $user->id, 'country' => 'SK', 'code' => 'SK-10',
        'rate' => 10, 'valid_to' => '2024-12-31',
    ]);

    $rule = new VatRateInCatalog($user->id, 'SK', '2026-01-01');

    expect(passesVatRateInCatalog($rule, 10))->toBeFalse();
});

it('passes for a historical rate valid on its own date', function (): void {
    $user = createUser();
    VatRate::factory()->create([
        'user_id' => $user->id, 'country' => 'SK', 'code' => 'SK-10',
        'rate' => 10, 'valid_to' => '2024-12-31',
    ]);

    $rule = new VatRateInCatalog($user->id, 'SK', '2024-06-01');

    expect(passesVatRateInCatalog($rule, 10))->toBeTrue();
});
