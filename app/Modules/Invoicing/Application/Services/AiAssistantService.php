<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\AiAssistantServiceInterface;
use App\Modules\Invoicing\Application\Contracts\ByokCredentialResolverInterface;
use App\Modules\Invoicing\Application\Contracts\UsageQuotaInterface;
use App\Modules\Invoicing\Application\DTOs\AiCompletionResult;
use App\Modules\Invoicing\Domain\Contracts\LlmProviderDriver;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Domain\Exceptions\LlmExtractionException;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;

/**
 * Phase 3 Part C (docs/plans/MCP_AI_ASSISTANT_EXPANSION_PLAN.md) — the
 * shared free-text completion path for every "AI asistované návrhy"
 * capability (dashboard summary, reminder-tone draft, low-confidence
 * category fallback). Mirrors FieldExtractorFactory's decision tree exactly
 * (same kill switch, same BYOK-before-quota order, same "never throw,
 * degrade to a reason" contract) rather than each capability reimplementing
 * it — the tree only needs to be correct once.
 *
 * Reuses LlmFieldExtractor's driver contract's extractJson() with a
 * trivial one-field schema instead of adding a second driver method: every
 * provider already has to support forcing structured JSON back (that's the
 * whole reason extractJson() exists), so a {"answer": string} schema gets a
 * plain-text completion through the exact same, already-tested code path.
 */
final readonly class AiAssistantService implements AiAssistantServiceInterface
{
    private const string QUOTA_FEATURE = 'ai_assistant_monthly';

    private const array ANSWER_SCHEMA = [
        'type' => 'object',
        'properties' => ['answer' => ['type' => 'string']],
        'required' => ['answer'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private ByokCredentialResolverInterface $byokCredentials,
        private LlmProviderRegistry $providers,
        private UsageQuotaInterface $usageQuota,
    ) {}

    public function complete(Account&ProvidesPlanEntitlements $owner, string $prompt): AiCompletionResult
    {
        if ((bool) config('qasa.ai.disabled', false)) {
            return AiCompletionResult::disabled();
        }

        if ($owner->hasFeature('ai_byok')) {
            $credential = $this->byokCredentials->forOwner($owner);

            if ($credential !== null) {
                try {
                    return $this->ask($this->providers->driverFor($credential->provider), $credential->api_key, $prompt);
                } catch (LlmExtractionException) {
                    return AiCompletionResult::unavailable();
                }
            }
        }

        if (! $owner->hasFeature('ai_assistant')) {
            return AiCompletionResult::unavailable();
        }

        $platformKey = config('services.anthropic.api_key');

        if (! is_string($platformKey) || $platformKey === '') {
            return AiCompletionResult::unavailable();
        }

        if (! $this->usageQuota->consume($owner, self::QUOTA_FEATURE)) {
            return AiCompletionResult::quotaExhausted();
        }

        try {
            return $this->ask($this->providers->driverFor(AiProvider::Anthropic), $platformKey, $prompt);
        } catch (LlmExtractionException) {
            $this->usageQuota->refund($owner, self::QUOTA_FEATURE);

            return AiCompletionResult::unavailable();
        }
    }

    /**
     * @throws LlmExtractionException
     */
    private function ask(LlmProviderDriver $driver, string $apiKey, string $prompt): AiCompletionResult
    {
        $content = [['type' => 'text', 'text' => $prompt]];

        $result = $driver->extractJson($content, self::ANSWER_SCHEMA, $apiKey);
        $answer = $result['answer'] ?? null;

        return is_string($answer) && $answer !== ''
            ? AiCompletionResult::ok($answer, $driver->provider()->value, $driver->model())
            : AiCompletionResult::unavailable();
    }
}
