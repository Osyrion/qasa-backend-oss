<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Invoicing\Domain\Contracts\ExtractionResult;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Throwable;

/**
 * OCR via the system `tesseract-ocr` binary (thiagoalessio/tesseract_ocr).
 * MVP scope: runs directly on image/* files only — image-only PDFs are not
 * rasterized here, they fall through CompositeExtractor as a failed scan.
 */
final class TesseractExtractor implements InvoiceTextExtractor
{
    public function extract(string $absolutePath, string $mime): ExtractionResult
    {
        if (! str_starts_with($mime, 'image/')) {
            return new ExtractionResult('', 'tesseract');
        }

        try {
            $languages = explode('+', (string) config('invoicing.inbox.ocr_languages', 'slk+ces+eng'));

            $text = trim((new TesseractOCR($absolutePath))
                ->lang(...$languages)
                ->run());
        } catch (Throwable) {
            $text = '';
        }

        return new ExtractionResult($text, 'tesseract');
    }
}
