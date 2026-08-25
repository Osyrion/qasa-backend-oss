<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\VatControlStatementReportData;
use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;

/**
 * SK kontrolný výkaz DPH / CZ kontrolní hlášení: classifies a tenant's
 * issued/received documents for a period into the country's own section
 * codes and thresholds, and renders the XML draft. Document collection
 * (the DB query) stays generic in Invoicing — only country-specific
 * classification and rendering live behind this contract.
 */
interface ControlStatementBuilder
{
    public function classify(string $userId, int $year, ?int $quarter = null, ?int $month = null): VatControlStatementReportData;

    public function toXml(VatControlStatementReportData $report, SupplierProfile $supplier): string;

    /**
     * @return list<string> caveats specific to the XML draft
     */
    public function assumptions(): array;
}
