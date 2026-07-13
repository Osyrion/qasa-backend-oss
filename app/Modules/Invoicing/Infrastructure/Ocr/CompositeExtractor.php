<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Invoicing\Domain\Contracts\ExtractionResult;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;

/**
 * Orchestrates extraction: PDF text layer via pdfparser first, then
 * Tesseract OCR for images. A PDF without a sufficient text layer (e.g. a
 * scanned document) is rasterized page by page and each page run through
 * the same Tesseract OCR — if that still yields nothing (rasterizer or OCR
 * binary unavailable, or a genuinely blank page), it degrades to the
 * pre-rasterization behaviour: empty text, item ends up failed.
 */
final class CompositeExtractor implements InvoiceTextExtractor
{
    public function __construct(
        private readonly PdfParserExtractor $pdfParser,
        private readonly TesseractExtractor $tesseract,
        private readonly PdfRasterizer $rasterizer,
    ) {}

    public function extract(string $absolutePath, string $mime): ExtractionResult
    {
        if ($mime === 'application/pdf') {
            $result = $this->pdfParser->extract($absolutePath, $mime);

            return $this->pdfParser->hasSufficientText($result)
                ? $result
                : $this->extractFromRasterizedPages($absolutePath);
        }

        if (str_starts_with($mime, 'image/')) {
            return $this->tesseract->extract($absolutePath, $mime);
        }

        return new ExtractionResult('', 'none');
    }

    private function extractFromRasterizedPages(string $absolutePdfPath): ExtractionResult
    {
        $pages = $this->rasterizer->rasterize($absolutePdfPath);

        try {
            $texts = [];

            foreach ($pages as $pagePath) {
                $ocr = $this->tesseract->extract($pagePath, 'image/png');

                if (trim($ocr->text) !== '') {
                    $texts[] = trim($ocr->text);
                }
            }

            $combined = trim(implode("\n", $texts));

            if ($combined !== '') {
                return new ExtractionResult($combined, 'pdftoppm+tesseract');
            }
        } finally {
            $this->rasterizer->cleanup($pages);
        }

        return new ExtractionResult('', 'pdfparser');
    }
}
