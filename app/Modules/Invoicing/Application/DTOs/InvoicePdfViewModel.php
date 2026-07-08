<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceWorkReportLine;
use App\Modules\Invoicing\Domain\Services\VatRecapRow;
use Illuminate\Support\Collection;

/**
 * Everything the PDF blade needs, precomputed so the template stays logic-free.
 */
final readonly class InvoicePdfViewModel
{
    /**
     * @param  array<string, mixed>  $supplier
     * @param  array<string, string>  $supplierTaxLines  label => value
     * @param  array<string, mixed>  $client
     * @param  array<string, string>  $clientTaxLines  label => value
     * @param  array<string, mixed>|null  $bank
     * @param  list<VatRecapRow>  $vatRecap
     * @param  list<VatRecapRow>|null  $czkRecap
     * @param  Collection<int, InvoiceWorkReportLine>  $workReportLines
     */
    public function __construct(
        public Invoice $invoice,
        public string $documentTitle,
        public bool $isTaxDocument,
        public ?string $relatedInvoiceNumber,
        public array $supplier,
        public array $supplierTaxLines,
        public ?string $logoDataUri,
        public array $client,
        public array $clientTaxLines,
        public ?array $bank,
        public array $vatRecap,
        public ?array $czkRecap,
        public ?float $exchangeRate,
        public ?string $qrDataUri,
        public ?string $footerText,
        public Collection $workReportLines,
    ) {}

    public function hasWorkReport(): bool
    {
        return $this->workReportLines->isNotEmpty();
    }

    public function totalHours(): float
    {
        return round((float) $this->workReportLines->sum('hours'), 2);
    }
}
