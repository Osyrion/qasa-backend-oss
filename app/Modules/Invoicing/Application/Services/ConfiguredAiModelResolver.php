<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Infrastructure\Ocr\ByokCredentialResolver;
use App\Modules\Shared\Application\Contracts\AiModelResolver;

/**
 * Real implementation of Shared's AiModelResolver: mirrors the BYOK-before-
 * platform order FieldExtractorFactory and AiAssistantService both apply, so
 * the transparency notice names the provider that would actually receive the
 * account's documents — not the one configured for everyone else.
 *
 * Reports the provider/model even when the account's plan or switch would
 * currently refuse the call: "who would get my data if I turned this on" is
 * exactly what a user reads this notice to find out. Whether it runs at all
 * is the per-feature `enabled` flag's job.
 */
final readonly class ConfiguredAiModelResolver implements AiModelResolver
{
    public function __construct(
        private ByokCredentialResolver $byokCredentials,
        private LlmProviderRegistry $providers,
    ) {}

    public function resolveFor(User $owner): array
    {
        $credential = $owner->accountOwner()->hasFeature('ai_byok')
            ? $this->byokCredentials->forOwner($owner)
            : null;

        if ($credential !== null) {
            return [
                'provider' => $credential->provider->value,
                'model' => $this->providers->driverFor($credential->provider)->model(),
                'byok' => true,
            ];
        }

        $platformKey = config('services.anthropic.api_key');

        if (! is_string($platformKey) || $platformKey === '') {
            return ['provider' => null, 'model' => null, 'byok' => false];
        }

        return [
            'provider' => AiProvider::Anthropic->value,
            'model' => $this->providers->driverFor(AiProvider::Anthropic)->model(),
            'byok' => false,
        ];
    }
}
