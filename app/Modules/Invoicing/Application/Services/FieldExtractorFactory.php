<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\ByokCredentialResolverInterface;
use App\Modules\Invoicing\Application\Contracts\LlmFieldExtractorFactoryInterface;
use App\Modules\Invoicing\Application\Contracts\UsageQuotaInterface;
use App\Modules\Invoicing\Application\DTOs\FieldExtractorSelection;
use App\Modules\Invoicing\Domain\Contracts\InvoiceFieldExtractor;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesAiPreferences;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;

/**
 * Decides, per inbox item owner, whether extraction runs on the regex
 * heuristics or the LLM — see
 * docs/plans/BYOK_MULTI_PROVIDER_EXTRACTION_PLAN.md §4:
 *
 * 1. QASA_AI_DISABLED global kill switch (every AI path, phase 3 Part C)
 *    or INVOICING_EXTRACTION_DRIVER=regex kill switch         → regex
 * 2. account owner's ai_extraction_enabled switch is off    → regex
 * 3. ai_byok feature not granted                            → regex
 * 4. BYOK credential exists                                 → LLM via its
 *    driver, no quota touched
 * 5. otherwise, platform branch (unchanged): ai_extraction feature granted
 *    and quota consume() ok                                 → LLM, quota
 *    consumed; quota spent                                  → regex
 *    (quotaExhausted flag set only when the tier has ai_extraction but the
 *    period's quota is spent)
 */
final readonly class FieldExtractorFactory
{
    public function __construct(
        private ByokCredentialResolverInterface $byokCredentials,
        private LlmFieldExtractorFactoryInterface $llmExtractors,
        private UsageQuotaInterface $usageQuota,
        // Bound to RegexFieldExtractor — it is the fallback every branch below
        // returns to, and the only extractor the container can build alone.
        private InvoiceFieldExtractor $regexExtractor,
    ) {}

    public function forOwner(Account&ProvidesAiPreferences&ProvidesPlanEntitlements $owner): FieldExtractorSelection
    {
        if ((bool) config('qasa.ai.disabled', false)) {
            return new FieldExtractorSelection($this->regexExtractor);
        }

        if ((string) config('invoicing.inbox.extraction.driver', 'regex') === 'regex') {
            return new FieldExtractorSelection($this->regexExtractor);
        }

        if (! $owner->aiExtractionEnabled()) {
            return new FieldExtractorSelection($this->regexExtractor);
        }

        if (! $owner->hasFeature('ai_byok')) {
            return new FieldExtractorSelection($this->regexExtractor);
        }

        $credential = $this->byokCredentials->forOwner($owner);

        if ($credential !== null) {
            return new FieldExtractorSelection(
                $this->llmExtractors->make($credential->provider, $credential->api_key, 'ai_byok'),
                credential: $credential,
            );
        }

        if (! $owner->hasFeature('ai_extraction')) {
            return new FieldExtractorSelection($this->regexExtractor);
        }

        $platformKey = config('services.anthropic.api_key');

        if (! is_string($platformKey) || $platformKey === '') {
            return new FieldExtractorSelection($this->regexExtractor);
        }

        if ($this->usageQuota->consume($owner, 'ai_extraction_monthly')) {
            return new FieldExtractorSelection(
                $this->llmExtractors->make(AiProvider::Anthropic, $platformKey, 'ai'),
                quotaConsumed: true,
            );
        }

        return new FieldExtractorSelection($this->regexExtractor, quotaExhausted: true);
    }

    /**
     * Called by the job when a consumed-quota LLM call itself fails —
     * the owner shouldn't be charged for a suggestion they never got.
     */
    public function refund(Account&ProvidesPlanEntitlements $owner): void
    {
        $this->usageQuota->refund($owner, 'ai_extraction_monthly');
    }
}
