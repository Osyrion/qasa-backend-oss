<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Contracts;

/**
 * Extraction point of the invoice inbox scanner. Implementations turn a
 * stored file into raw text; an AI-based extractor can be added later
 * behind this same interface without changing ScanInboxAction.
 */
interface InvoiceTextExtractor
{
    public function extract(string $absolutePath, string $mime): ExtractionResult;
}
