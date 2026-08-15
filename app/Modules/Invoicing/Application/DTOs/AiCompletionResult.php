<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

/**
 * AiAssistantService's outcome for one completion request — never an
 * exception, per phase 3 Part C's "AI navrhuje, človek potvrdzuje" /
 * "vyčerpaná kvóta degraduje na návrh 'bez AI'" principle
 * (docs/plans/MCP_AI_ASSISTANT_EXPANSION_PLAN.md). $reason is null only
 * when $text is present; callers show a plain "AI unavailable right now"
 * style message keyed off $reason otherwise, never a 4xx/5xx.
 *
 * $provider/$model travel with the text so the caller can hand them to
 * AiOutputMarker — text a user is about to send to their client has to say
 * which model wrote it (AI Act art. 50 ods. 2), and only this layer knows.
 */
final readonly class AiCompletionResult
{
    private function __construct(
        public ?string $text,
        public ?string $reason,
        public ?string $provider = null,
        public ?string $model = null,
    ) {}

    public static function ok(string $text, ?string $provider = null, ?string $model = null): self
    {
        return new self($text, null, $provider, $model);
    }

    public static function disabled(): self
    {
        return new self(null, 'disabled');
    }

    public static function unavailable(): self
    {
        return new self(null, 'unavailable');
    }

    public static function quotaExhausted(): self
    {
        return new self(null, 'quota_exhausted');
    }
}
