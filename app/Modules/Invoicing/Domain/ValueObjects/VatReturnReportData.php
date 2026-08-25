<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

/**
 * Read-only basis for the VAT return itself (SK: priznanie DPH / CZ:
 * přiznání DPH) — the country-agnostic aggregate (per-rate output totals,
 * self-assessed EU/import totals, deductible input totals). Mapping these
 * onto a specific tlačivo's row numbers is the country builder's job (see
 * Taxation\Domain\Contracts\VatReturnBuilder) — this DTO carries the
 * underlying figures, not row keys, so it stays reusable once a CZ builder
 * exists too.
 */
final readonly class VatReturnReportData
{
    /**
     * @param  array<int|string, array{base: float, vat: float}>  $outputByRate  keyed by rate — PHP normalizes a numeric-string key like "23"/"10"/"5" to an int key, so both are possible; domestic supplies only, excludes both EU and domestic reverse-charge invoices
     * @param  list<string>  $assumptions  known simplifications applied while building this report
     */
    public function __construct(
        public string $country,
        public int $year,
        public ?int $month,
        public ?int $quarter,
        public array $outputByRate,
        public float $euAcquisitionBase,
        public float $euAcquisitionVat,
        public float $importBase,
        public float $importVat,
        public float $domesticInputBase,
        public float $domesticInputVat,
        public array $assumptions,
    ) {}
}
