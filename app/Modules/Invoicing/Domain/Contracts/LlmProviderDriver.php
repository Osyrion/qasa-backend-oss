<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Contracts;

use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Infrastructure\Ocr\LlmExtractionException;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * One implementation per LLM provider (AnthropicDriver today; OpenAI/
 * DeepSeek later, registered via LlmProviderRegistry) — LlmFieldExtractor
 * stays provider-agnostic (prompt, field schema, vision fallback) and only
 * ever talks to this contract, never to a provider's HTTP API directly.
 */
interface LlmProviderDriver
{
    public function provider(): AiProvider;

    /**
     * Turns $content (message blocks built by LlmFieldExtractor) plus a
     * JSON Schema of the fields to extract into structured JSON — how that
     * schema is communicated to the provider (forced tool call, JSON mode,
     * ...) is entirely the driver's concern.
     *
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     *
     * @throws LlmExtractionException
     */
    public function extractJson(array $content, array $schema, string $apiKey): array;

    /**
     * Cheapest possible round-trip to confirm a key actually works, without
     * running a real extraction.
     *
     * @throws DomainException
     */
    public function verifyKey(string $apiKey): bool;
}
