<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

/**
 * Maps a party's country to the native labels of its tax identifiers.
 * Values in `ico`/`dic`/`vat_id` columns are printed under these labels;
 * only fields returned here are shown on the invoice.
 */
final class CountryTaxLabelMap
{
    /**
     * @return array<string, string> field name => printed label
     */
    public static function labelsFor(string $countryCode, bool $isVatPayer): array
    {
        $country = strtoupper($countryCode);

        return match ($country) {
            'CZ' => ['ico' => 'IČO', 'dic' => 'DIČ'],
            'SK' => $isVatPayer
                ? ['ico' => 'IČO', 'dic' => 'DIČ', 'vat_id' => 'IČ DPH']
                : ['ico' => 'IČO', 'dic' => 'DIČ'],
            'DE', 'AT' => ['dic' => 'Steuernummer', 'vat_id' => 'USt-IdNr.'],
            'PL' => ['dic' => 'NIP', 'vat_id' => 'VAT ID'],
            'HU' => ['dic' => 'Adószám', 'vat_id' => 'VAT ID'],
            default => ['ico' => 'Reg. No.', 'dic' => 'Tax ID', 'vat_id' => 'VAT ID'],
        };
    }
}
