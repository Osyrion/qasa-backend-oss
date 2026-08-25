<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Invoicing\Application\Contracts\LlmFieldExtractorFactoryInterface;
use App\Modules\Invoicing\Application\Services\LlmProviderRegistry;
use App\Modules\Invoicing\Domain\Contracts\InvoiceFieldExtractor;
use App\Modules\Invoicing\Domain\Enums\AiProvider;

final readonly class LlmFieldExtractorFactory implements LlmFieldExtractorFactoryInterface
{
    public function __construct(
        private LlmProviderRegistry $providers,
        private PdfRasterizer $rasterizer,
    ) {}

    /**
     * @param  'ai'|'ai_byok'  $source
     */
    public function make(AiProvider $provider, string $apiKey, string $source): InvoiceFieldExtractor
    {
        return new LlmFieldExtractor(
            $this->providers->driverFor($provider),
            $apiKey,
            $this->rasterizer,
            $source,
            $provider,
        );
    }
}
