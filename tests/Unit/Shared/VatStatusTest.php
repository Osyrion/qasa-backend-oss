<?php

declare(strict_types=1);

use App\Modules\Shared\Enums\VatStatus;

it('only the payer status can charge VAT', function (): void {
    expect(VatStatus::Payer->canChargeVat())->toBeTrue()
        ->and(VatStatus::Identified->canChargeVat())->toBeFalse()
        ->and(VatStatus::NonPayer->canChargeVat())->toBeFalse();
});

it('payer and identified both hold a VAT ID', function (): void {
    expect(VatStatus::Payer->hasVatId())->toBeTrue()
        ->and(VatStatus::Identified->hasVatId())->toBeTrue()
        ->and(VatStatus::NonPayer->hasVatId())->toBeFalse();
});

it('isVatPayer is true only for the payer status', function (): void {
    expect(VatStatus::Payer->isVatPayer())->toBeTrue()
        ->and(VatStatus::Identified->isVatPayer())->toBeFalse()
        ->and(VatStatus::NonPayer->isVatPayer())->toBeFalse();
});

it('derives status from the legacy boolean', function (): void {
    expect(VatStatus::fromLegacyBool(true))->toBe(VatStatus::Payer)
        ->and(VatStatus::fromLegacyBool(false))->toBe(VatStatus::NonPayer);
});
