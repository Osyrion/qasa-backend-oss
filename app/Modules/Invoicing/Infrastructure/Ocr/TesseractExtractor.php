<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Invoicing\Domain\Contracts\ExtractionResult;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * OCR via the system `tesseract-ocr` binary, invoked directly through
 * Illuminate's Process (not the thiagoalessio/tesseract_ocr wrapper) so a
 * runaway process actually gets killed on timeout — the wrapper's own
 * timeout only stops it from reading further output, it never terminates
 * the underlying process. MVP scope: runs directly on image/* files only —
 * image-only PDFs are rasterized by PdfRasterizer first, one page image at
 * a time, each of which comes back through this same extract().
 */
final class TesseractExtractor implements InvoiceTextExtractor
{
    public function extract(string $absolutePath, string $mime): ExtractionResult
    {
        if (! str_starts_with($mime, 'image/')) {
            return new ExtractionResult('', 'tesseract');
        }

        if (! $this->isWithinPixelLimit($absolutePath)) {
            return new ExtractionResult('', 'tesseract');
        }

        $binary = (string) config('invoicing.inbox.tesseract_path', 'tesseract');
        $languages = (string) config('invoicing.inbox.ocr_languages', 'slk+ces+eng');
        $timeout = (int) config('invoicing.inbox.ocr_timeout', 60);

        try {
            $result = Process::timeout($timeout)->run([$binary, $absolutePath, 'stdout', '-l', $languages]);
            $text = $result->successful() ? trim($result->output()) : '';
        } catch (Throwable) {
            $text = '';
        }

        return new ExtractionResult($text, 'tesseract');
    }

    /**
     * Decompression-bomb guard: a well-formed but absurdly large image
     * (e.g. a crafted PNG claiming billions of pixels) can otherwise tie up
     * the OCR worker's memory/CPU for a very long time. getimagesize()
     * reads only the header, so this is cheap even for a huge file.
     */
    private function isWithinPixelLimit(string $absolutePath): bool
    {
        $dimensions = @getimagesize($absolutePath);

        if ($dimensions === false) {
            return false;
        }

        $maxPixelsPerSide = (int) config('invoicing.inbox.ocr_max_pixels_per_side', 10000);

        return $dimensions[0] <= $maxPixelsPerSide && $dimensions[1] <= $maxPixelsPerSide;
    }
}
