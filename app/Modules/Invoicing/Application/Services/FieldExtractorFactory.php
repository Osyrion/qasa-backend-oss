<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Contracts\UsageQuotaInterface;
use App\Modules\Invoicing\Application\DTOs\FieldExtractorSelection;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Infrastructure\Ocr\ByokCredentialResolver;
use App\Modules\Invoicing\Infrastructure\Ocr\LlmFieldExtractor;
use App\Modules\Invoicing\Infrastructure\Ocr\PdfRasterizer;
use App\Modules\Invoicing\Infrastructure\Ocr\RegexFieldExtractor;

/**
 * Decides, per inbox item owner, whether extraction runs on the regex
 * heuristics or the LLM — see
 * docs/plans/BYOK_MULTI_PROVIDER_EXTRACTION_PLAN.md §4:
 *
 * 1. INVOICING_EXTRACTION_DRIVER=regex kill switch          → regex
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
        private ByokCredentialResolver $byokCredentials,
        private LlmProviderRegistry $providers,
        private UsageQuotaInterface $usageQuota,
        private RegexFieldExtractor $regexExtractor,
        private PdfRasterizer $rasterizer,
    ) {}

    public function forOwner(User $owner): FieldExtractorSelection
    {
        if ((string) config('invoicing.inbox.extraction.driver', 'regex') === 'regex') {
            return new FieldExtractorSelection($this->regexExtractor);
        }

        $account = $owner->accountOwner();

        if (! $account->ai_extraction_enabled) {
            return new FieldExtractorSelection($this->regexExtractor);
        }

        if (! $account->hasFeature('ai_byok')) {
            return new FieldExtractorSelection($this->regexExtractor);
        }

        $credential = $this->byokCredentials->forOwner($owner);

        if ($credential !== null) {
            $driver = $this->providers->driverFor($credential->provider);

            return new FieldExtractorSelection(
                new LlmFieldExtractor($driver, $credential->api_key, $this->rasterizer, 'ai_byok', $credential->provider),
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
            $driver = $this->providers->driverFor(AiProvider::Anthropic);

            return new FieldExtractorSelection(
                new LlmFieldExtractor($driver, $platformKey, $this->rasterizer, 'ai', AiProvider::Anthropic),
                quotaConsumed: true,
            );
        }

        return new FieldExtractorSelection($this->regexExtractor, quotaExhausted: true);
    }

    /**
     * Called by the job when a consumed-quota LLM call itself fails —
     * the owner shouldn't be charged for a suggestion they never got.
     */
    public function refund(User $owner): void
    {
        $this->usageQuota->refund($owner, 'ai_extraction_monthly');
    }
}
