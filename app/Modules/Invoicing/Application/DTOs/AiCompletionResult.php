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
 */
final readonly class AiCompletionResult
{
    private function __construct(
        public ?string $text,
        public ?string $reason,
    ) {}

    public static function ok(string $text): self
    {
        return new self($text, null);
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
