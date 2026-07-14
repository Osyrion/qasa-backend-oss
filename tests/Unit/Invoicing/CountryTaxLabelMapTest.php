<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Services\CountryTaxLabelMap;
use App\Modules\Shared\Enums\VatStatus;

it('prints IČ DPH for a Slovak VAT payer', function (): void {
    expect(CountryTaxLabelMap::labelsFor('SK', VatStatus::Payer))->toBe([
        'ico' => 'IČO', 'dic' => 'DIČ', 'vat_id' => 'IČ DPH',
    ]);
});

it('prints IČ DPH for a Slovak identified person too', function (): void {
    expect(CountryTaxLabelMap::labelsFor('SK', VatStatus::Identified))->toBe([
        'ico' => 'IČO', 'dic' => 'DIČ', 'vat_id' => 'IČ DPH',
    ]);
});

it('omits IČ DPH for a Slovak non-payer', function (): void {
    expect(CountryTaxLabelMap::labelsFor('SK', VatStatus::NonPayer))->toBe([
        'ico' => 'IČO', 'dic' => 'DIČ',
    ]);
});

it('uses IČO and DIČ for Czech clients regardless of status', function (): void {
    expect(CountryTaxLabelMap::labelsFor('CZ', VatStatus::Payer))->toBe([
        'ico' => 'IČO', 'dic' => 'DIČ',
    ]);
});

it('uses Steuernummer for Germany and Austria', function (string $country): void {
    $labels = CountryTaxLabelMap::labelsFor($country, VatStatus::Payer);

    expect($labels['dic'])->toBe('Steuernummer')
        ->and($labels['vat_id'])->toBe('USt-IdNr.')
        ->and($labels)->not->toHaveKey('ico');
})->with(['DE', 'AT']);

it('uses NIP for Poland and Adószám for Hungary', function (): void {
    expect(CountryTaxLabelMap::labelsFor('PL', VatStatus::Payer)['dic'])->toBe('NIP')
        ->and(CountryTaxLabelMap::labelsFor('HU', VatStatus::NonPayer)['dic'])->toBe('Adószám');
});

it('falls back to generic labels for unknown countries', function (): void {
    expect(CountryTaxLabelMap::labelsFor('US', VatStatus::NonPayer))->toBe([
        'ico' => 'Reg. No.', 'dic' => 'Tax ID', 'vat_id' => 'VAT ID',
    ]);
});

it('is case-insensitive on the country code', function (): void {
    expect(CountryTaxLabelMap::labelsFor('sk', VatStatus::Payer))->toHaveKey('vat_id');
});
