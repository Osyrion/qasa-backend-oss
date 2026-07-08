<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Services\CountryTaxLabelMap;

it('prints IČ DPH for a Slovak VAT payer', function (): void {
    expect(CountryTaxLabelMap::labelsFor('SK', true))->toBe([
        'ico' => 'IČO', 'dic' => 'DIČ', 'vat_id' => 'IČ DPH',
    ]);
});

it('omits IČ DPH for a Slovak non-payer', function (): void {
    expect(CountryTaxLabelMap::labelsFor('SK', false))->toBe([
        'ico' => 'IČO', 'dic' => 'DIČ',
    ]);
});

it('uses IČO and DIČ for Czech clients', function (): void {
    expect(CountryTaxLabelMap::labelsFor('CZ', true))->toBe([
        'ico' => 'IČO', 'dic' => 'DIČ',
    ]);
});

it('uses Steuernummer for Germany and Austria', function (string $country): void {
    $labels = CountryTaxLabelMap::labelsFor($country, true);

    expect($labels['dic'])->toBe('Steuernummer')
        ->and($labels['vat_id'])->toBe('USt-IdNr.')
        ->and($labels)->not->toHaveKey('ico');
})->with(['DE', 'AT']);

it('uses NIP for Poland and Adószám for Hungary', function (): void {
    expect(CountryTaxLabelMap::labelsFor('PL', true)['dic'])->toBe('NIP')
        ->and(CountryTaxLabelMap::labelsFor('HU', false)['dic'])->toBe('Adószám');
});

it('falls back to generic labels for unknown countries', function (): void {
    expect(CountryTaxLabelMap::labelsFor('US', false))->toBe([
        'ico' => 'Reg. No.', 'dic' => 'Tax ID', 'vat_id' => 'VAT ID',
    ]);
});

it('is case-insensitive on the country code', function (): void {
    expect(CountryTaxLabelMap::labelsFor('sk', true))->toHaveKey('vat_id');
});
