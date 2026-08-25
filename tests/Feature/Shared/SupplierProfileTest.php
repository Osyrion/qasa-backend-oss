<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Enums\VatStatus;

/**
 * The account's business identity, taken off the account and handed to
 * whoever prints or files it — see SupplierProfile for why that boundary
 * exists at all.
 */
it('describes the account as a supplier', function (): void {
    $user = createUser([
        'company_name' => 'Acme s.r.o.',
        'name' => 'Jana',
        'surname' => 'Nováková',
        'ico' => '12345678',
        'dic' => '1234567890',
        'vat_id' => 'SK1234567890',
        'vat_status' => VatStatus::Payer->value,
        'address' => 'Hlavná 1',
        'city' => 'Košice',
        'postal_code' => '04001',
        'country' => 'SK',
        'default_currency' => Currency::EUR->value,
    ]);

    $profile = $user->supplierProfile();

    expect($profile)->toBeInstanceOf(SupplierProfile::class)
        // company_name is the sole source of the printed name once set.
        ->and($profile->name)->toBe('Acme s.r.o.')
        // The person's own name stays available for filings that print it
        // separately (the CZ VetaP header, say).
        ->and($profile->firstName)->toBe('Jana')
        ->and($profile->lastName)->toBe('Nováková')
        ->and($profile->ico)->toBe('12345678')
        ->and($profile->dic)->toBe('1234567890')
        ->and($profile->vatId)->toBe('SK1234567890')
        ->and($profile->vatStatus)->toBe(VatStatus::Payer)
        ->and($profile->address)->toBe('Hlavná 1')
        ->and($profile->city)->toBe('Košice')
        ->and($profile->postalCode)->toBe('04001')
        ->and($profile->country)->toBe('SK')
        ->and($profile->defaultCurrency)->toBe(Currency::EUR);
});

it('falls back to the person full name before a company name is set', function (): void {
    $user = createUser(['company_name' => null, 'name' => 'Jana', 'surname' => 'Nováková']);

    expect($user->supplierProfile()->name)->toBe($user->full_name);
});
