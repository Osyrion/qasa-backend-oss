<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Contracts\FieldExtractionInput;
use App\Modules\Invoicing\Domain\Contracts\LlmProviderDriver;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Infrastructure\Ocr\LlmFieldExtractor;
use App\Modules\Invoicing\Infrastructure\Ocr\PdfRasterizer;

/**
 * A recording fake — LlmFieldExtractor is provider-agnostic, so it's tested
 * against this contract double rather than real HTTP; AnthropicDriverTest
 * covers the Anthropic-specific request/response wire format.
 */
final class FakeLlmProviderDriver implements LlmProviderDriver
{
    /** @var list<array<string, mixed>>|null */
    public ?array $lastContent = null;

    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(private readonly array $fields = ['ico' => '12345678']) {}

    public function provider(): AiProvider
    {
        return AiProvider::Anthropic;
    }

    public function model(): string
    {
        return 'fake-model-1';
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function extractJson(array $content, array $schema, string $apiKey): array
    {
        $this->lastContent = $content;

        return $this->fields;
    }

    public function verifyKey(string $apiKey): bool
    {
        return true;
    }
}

it('parses driver output into suggestions, dropping null/empty values', function (): void {
    $driver = new FakeLlmProviderDriver(['supplier_invoice_number' => 'INV-2026-001', 'ico' => '12345678', 'currency' => null]);
    $extractor = new LlmFieldExtractor($driver, 'sk-ant-test', new PdfRasterizer, 'ai', AiProvider::Anthropic);

    $result = $extractor->parse(new FieldExtractionInput(text: str_repeat('Faktúra text ', 100)));

    expect($result->source)->toBe('ai')
        ->and($result->provider)->toBe('anthropic')
        // Recorded from the driver, not from config — the art. 50 marking on
        // this row has to name the model that actually answered.
        ->and($result->model)->toBe('fake-model-1')
        ->and($result->suggestions['supplier_invoice_number'])->toBe('INV-2026-001')
        ->and($result->suggestions['ico'])->toBe('12345678')
        ->and($result->suggestions)->not->toHaveKey('currency');
});

it('carries the BYOK source through to the result', function (): void {
    $driver = new FakeLlmProviderDriver;
    $extractor = new LlmFieldExtractor($driver, 'sk-ant-secret', new PdfRasterizer, 'ai_byok', AiProvider::Anthropic);

    $result = $extractor->parse(new FieldExtractionInput(text: str_repeat('x', 600)));

    expect($result->source)->toBe('ai_byok');
});

it('sends plain text content when the OCR text is long enough', function (): void {
    $driver = new FakeLlmProviderDriver;
    $extractor = new LlmFieldExtractor($driver, 'key', new PdfRasterizer, 'ai', AiProvider::Anthropic);

    $extractor->parse(new FieldExtractionInput(text: str_repeat('x', 600)));

    $content = $driver->lastContent ?? [];

    expect($content)
        ->toHaveCount(1)
        ->and($content[0]['type'] ?? null)->toBe('text');
});

it('falls back to a vision request with image blocks when text is too sparse', function (): void {
    $driver = new FakeLlmProviderDriver;
    $extractor = new LlmFieldExtractor($driver, 'key', new PdfRasterizer, 'ai', AiProvider::Anthropic);

    $tmp = tempnam(sys_get_temp_dir(), 'ocrtest').'.png';
    file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

    $extractor->parse(new FieldExtractionInput(text: 'short', absolutePath: $tmp, mime: 'image/png'));

    $hasImageBlock = collect($driver->lastContent)->contains(fn (array $block): bool => ($block['type'] ?? null) === 'image');

    expect($hasImageBlock)->toBeTrue();

    @unlink($tmp);
});
