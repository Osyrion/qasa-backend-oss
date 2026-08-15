<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Invoicing\Domain\Contracts\FieldExtractionInput;
use App\Modules\Invoicing\Domain\Contracts\FieldExtractionResult;
use App\Modules\Invoicing\Domain\Contracts\InvoiceFieldExtractor;
use App\Modules\Invoicing\Domain\Contracts\LlmProviderDriver;
use App\Modules\Invoicing\Domain\Enums\AiProvider;

/**
 * Extracts supplier invoice fields via a pluggable LlmProviderDriver. This
 * class stays entirely provider-agnostic — prompt, field schema and vision
 * fallback only — everything Anthropic/OpenAI/DeepSeek-specific (HTTP call,
 * response parsing, auth headers) lives in the driver. One instance is
 * owner-specific (constructed by FieldExtractorFactory with an
 * already-resolved driver/key/source) — never a container singleton, since
 * these differ per BYOK/platform resolution.
 *
 * Every failure surfaces as LlmExtractionException; ProcessInboxItemJob
 * always catches it and falls back to RegexFieldExtractor — an inbox item
 * must never fail solely because the LLM call did (tries = 1, no retry).
 */
final readonly class LlmFieldExtractor implements InvoiceFieldExtractor
{
    /**
     * @param  'ai'|'ai_byok'  $source
     */
    public function __construct(
        private LlmProviderDriver $driver,
        private string $apiKey,
        private PdfRasterizer $rasterizer,
        private string $source,
        private AiProvider $provider,
    ) {}

    public function parse(FieldExtractionInput $input): FieldExtractionResult
    {
        $content = $this->buildContent($input);

        $fields = $this->driver->extractJson($content, $this->fieldSchema(), $this->apiKey);

        return new FieldExtractionResult(
            suggestions: array_filter($fields, static fn (mixed $value): bool => $value !== null && $value !== []),
            source: $this->source,
            provider: $this->provider->value,
            model: $this->driver->model(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildContent(FieldExtractionInput $input): array
    {
        $minChars = (int) config('invoicing.inbox.extraction.vision_min_chars', 500);
        $text = trim($input->text);

        if (mb_strlen($text) >= $minChars) {
            return [['type' => 'text', 'text' => $this->prompt($text)]];
        }

        $images = $this->rasterizeForVision($input);

        if ($images === []) {
            // No usable image either — proceed with whatever (possibly
            // sparse) text there is rather than failing outright.
            return [['type' => 'text', 'text' => $this->prompt($text)]];
        }

        $blocks = array_map(static fn (array $image): array => [
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['base64']],
        ], $images);

        $blocks[] = ['type' => 'text', 'text' => $this->prompt($text)];

        return $blocks;
    }

    /**
     * @return list<array{mime: string, base64: string}>
     */
    private function rasterizeForVision(FieldExtractionInput $input): array
    {
        if ($input->absolutePath === null) {
            return [];
        }

        $maxPages = (int) config('invoicing.inbox.extraction.vision_max_pages', 3);

        if ($input->mime === 'application/pdf') {
            $pages = $this->rasterizer->rasterize($input->absolutePath);

            try {
                return array_map(
                    static fn (string $path): array => ['mime' => 'image/png', 'base64' => base64_encode((string) file_get_contents($path))],
                    array_slice($pages, 0, $maxPages),
                );
            } finally {
                $this->rasterizer->cleanup($pages);
            }
        }

        if (is_string($input->mime) && str_starts_with($input->mime, 'image/')) {
            $data = @file_get_contents($input->absolutePath);

            return $data === false ? [] : [['mime' => $input->mime, 'base64' => base64_encode($data)]];
        }

        return [];
    }

    private function prompt(string $text): string
    {
        return 'Extract structured fields from this supplier invoice (Slovak or Czech). '.
            'Only report a value you can actually read in the document — never guess or infer a missing '.
            "value, leave it null instead.\n\n".
            ($text === '' ? '(no OCR text available — read the attached image)' : $text);
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldSchema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'supplier_invoice_number' => $nullableString,
                'ico' => $nullableString,
                'dic' => $nullableString,
                'vat_id' => $nullableString,
                'issued_at' => ['type' => ['string', 'null'], 'description' => 'ISO 8601 date'],
                'due_at' => ['type' => ['string', 'null'], 'description' => 'ISO 8601 date'],
                'taxable_supply_at' => ['type' => ['string', 'null'], 'description' => 'ISO 8601 date'],
                'total' => $nullableNumber,
                'variable_symbol' => $nullableString,
                'constant_symbol' => $nullableString,
                'specific_symbol' => $nullableString,
                'iban' => $nullableString,
                'account_number' => $nullableString,
                'bank_code' => $nullableString,
                'currency' => $nullableString,
                'vat_breakdown' => [
                    'type' => ['array', 'null'],
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'rate' => ['type' => 'number'],
                            'base' => ['type' => 'number'],
                            'vat' => ['type' => 'number'],
                        ],
                    ],
                ],
                'line_items' => [
                    'type' => ['array', 'null'],
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => ['type' => 'string'],
                            'quantity' => ['type' => 'number'],
                            'unit_price' => ['type' => 'number'],
                            'vat_rate' => ['type' => 'number'],
                            'amount' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
            'required' => [],
        ];
    }
}
