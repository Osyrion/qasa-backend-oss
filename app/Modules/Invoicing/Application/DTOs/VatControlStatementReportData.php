<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

/**
 * Read-only basis for the VAT control statement (SK: kontrolný výkaz DPH /
 * CZ: kontrolní hlášení). Section codes present differ by country — see
 * VatControlStatementService for the exact per-country classification.
 */
final readonly class VatControlStatementReportData
{
    /**
     * @param  array<string, list<VatControlStatementRowData>>  $rowSections  keyed by section code (A1, A2, A4, B1, B2, C1, C2, ...)
     * @param  array<string, list<VatControlStatementSummaryRowData>>  $summarySections  keyed by section code (A5, B3, ...), grouped by rate
     * @param  list<string>  $assumptions  known simplifications applied while building this report
     */
    public function __construct(
        public string $country,
        public int $year,
        public ?int $month,
        public ?int $quarter,
        public array $rowSections,
        public array $summarySections,
        public array $assumptions,
    ) {}
}
