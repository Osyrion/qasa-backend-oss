<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Contracts\InvoiceFieldExtractor;
use App\Modules\Invoicing\Domain\Enums\AiProvider;

/**
 * Builds the LLM-backed extractor for one owner's resolved key.
 *
 * Unlike every other extractor this one cannot be a container binding: the
 * provider driver, the API key and the source label differ per inbox item
 * (BYOK key vs. platform key), so it is constructed per call. That
 * construction is what kept FieldExtractorFactory naming three Infrastructure
 * classes — the driver registry, the rasterizer and the extractor itself.
 * Behind this contract they are the implementation's business, and the
 * decision logic in FieldExtractorFactory stays what it is about: which key,
 * which quota, which fallback.
 */
interface LlmFieldExtractorFactoryInterface
{
    /**
     * @param  'ai'|'ai_byok'  $source  the feature the call is billed to
     */
    public function make(AiProvider $provider, string $apiKey, string $source): InvoiceFieldExtractor;
}
