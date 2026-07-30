<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Contracts;

/**
 * Turns OCR'd invoice text (plus the original file, for vision fallback)
 * into field suggestions for the inbox review form. RegexFieldExtractor
 * (heuristic, always available) and LlmFieldExtractor (Claude, quota/BYOK
 * gated) both implement this — FieldExtractorFactory picks one per item.
 */
interface InvoiceFieldExtractor
{
    public function parse(FieldExtractionInput $input): FieldExtractionResult;
}
