<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Contracts;

/**
 * OCR'd text plus enough file info for an extractor to fall back to vision
 * (LLM) when the text is too sparse to trust — the regex extractor ignores
 * absolutePath/mime entirely.
 */
final readonly class FieldExtractionInput
{
    public function __construct(
        public string $text,
        public ?string $absolutePath = null,
        public ?string $mime = null,
    ) {}
}
