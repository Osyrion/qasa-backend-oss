<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Invoicing\Domain\Contracts\ExtractionResult;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extracts the text layer of a PDF via smalot/pdfparser. Scanned/image-only
 * PDFs have no text layer, so hasSufficientText() signals CompositeExtractor
 * to treat the result as empty rather than trusting a near-blank string.
 */
final class PdfParserExtractor implements InvoiceTextExtractor
{
    private const MIN_TEXT_LENGTH = 20;

    public function extract(string $absolutePath, string $mime): ExtractionResult
    {
        if ($mime !== 'application/pdf') {
            return new ExtractionResult('', 'pdfparser');
        }

        try {
            $text = trim((new Parser)->parseFile($absolutePath)->getText());
        } catch (Throwable) {
            $text = '';
        }

        return new ExtractionResult($text, 'pdfparser');
    }

    public function hasSufficientText(ExtractionResult $result): bool
    {
        return mb_strlen($result->text) >= self::MIN_TEXT_LENGTH;
    }
}
