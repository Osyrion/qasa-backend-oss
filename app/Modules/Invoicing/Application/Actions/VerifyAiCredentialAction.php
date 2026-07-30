<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\Services\LlmProviderRegistry;
use App\Modules\Invoicing\Domain\Models\AiCredential;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * Backs POST /api/v1/ai-credentials/{provider}/test — a cheap round-trip
 * against the provider to confirm the saved key actually works, recording
 * the result on the credential so the tenant sees it without re-testing.
 */
final class VerifyAiCredentialAction
{
    public function __construct(
        private readonly LlmProviderRegistry $providers,
    ) {}

    /**
     * @throws DomainException
     */
    public function execute(AiCredential $credential): bool
    {
        $driver = $this->providers->driverFor($credential->provider);
        $valid = $driver->verifyKey($credential->api_key);

        $credential->update([
            'verified_at' => $valid ? now() : null,
            'last_error' => $valid ? null : 'Key rejected by provider (401/403).',
        ]);

        return $valid;
    }
}
