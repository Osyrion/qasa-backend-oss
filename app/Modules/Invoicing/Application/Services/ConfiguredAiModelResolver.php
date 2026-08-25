<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\ByokCredentialResolverInterface;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Shared\Application\Contracts\AiModelResolver;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesAiPreferences;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;

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
        private ByokCredentialResolverInterface $byokCredentials,
        private LlmProviderRegistry $providers,
    ) {}

    public function resolveFor(Account&ProvidesAiPreferences&ProvidesPlanEntitlements $owner): array
    {
        $credential = $owner->hasFeature('ai_byok')
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
