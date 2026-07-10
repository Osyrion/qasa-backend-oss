<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Invoicing\Domain\Contracts\ExtractionResult;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;

/**
 * Orchestrates extraction: PDF text layer via pdfparser first, then
 * Tesseract OCR for images. An image-only PDF (no text layer) is not
 * rasterized in this MVP — it comes back empty and ScanInboxAction marks
 * the item as failed with a clear message.
 */
final class CompositeExtractor implements InvoiceTextExtractor
{
    public function __construct(
        private readonly PdfParserExtractor $pdfParser,
        private readonly TesseractExtractor $tesseract,
    ) {}

    public function extract(string $absolutePath, string $mime): ExtractionResult
    {
        if ($mime === 'application/pdf') {
            $result = $this->pdfParser->extract($absolutePath, $mime);

            return $this->pdfParser->hasSufficientText($result)
                ? $result
                : new ExtractionResult('', 'pdfparser');
        }

        if (str_starts_with($mime, 'image/')) {
            return $this->tesseract->extract($absolutePath, $mime);
        }

        return new ExtractionResult('', 'none');
    }
}
