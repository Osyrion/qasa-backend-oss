<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\VatReturnReportData;
use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;

/**
 * SK priznanie DPH / CZ přiznání DPH — the VAT return itself (not the
 * control statement or EU sales list). classify() aggregates a tenant's
 * documents for a period into country-agnostic totals; toXml() maps those
 * onto the country's own tlačivo row numbers and renders the draft.
 */
interface VatReturnBuilder
{
    public function classify(string $userId, int $year, ?int $quarter = null, ?int $month = null): VatReturnReportData;

    public function toXml(VatReturnReportData $report, SupplierProfile $supplier): string;

    /**
     * @return list<string> caveats specific to the XML draft
     */
    public function assumptions(): array;
}
