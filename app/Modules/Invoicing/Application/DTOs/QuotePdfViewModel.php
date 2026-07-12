<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Services\VatRecapRow;

/**
 * Everything the quote PDF blade needs, precomputed so the template stays
 * logic-free. A quote is never a tax document, has no reverse charge, no
 * bank details and no QR payment — the view model stays deliberately
 * smaller than InvoicePdfViewModel.
 */
final readonly class QuotePdfViewModel
{
    /**
     * @param  array<string, mixed>  $supplier
     * @param  array<string, string>  $supplierTaxLines  label => value
     * @param  array<string, mixed>  $client
     * @param  array<string, string>  $clientTaxLines  label => value
     * @param  list<VatRecapRow>  $vatRecap
     */
    public function __construct(
        public Quote $quote,
        public string $documentTitle,
        public array $supplier,
        public array $supplierTaxLines,
        public ?string $logoDataUri,
        public array $client,
        public array $clientTaxLines,
        public array $vatRecap,
        public ?string $footerText,
    ) {}
}
