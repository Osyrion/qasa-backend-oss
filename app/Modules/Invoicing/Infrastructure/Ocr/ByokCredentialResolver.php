<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Domain\Models\AiCredential;

/**
 * Resolves which BYOK credential (if any) an account should extract
 * invoices with, trying providers in the order configured at
 * invoicing.inbox.extraction.provider_priority. At v1 (single Anthropic
 * driver) that order is a no-op; once a second provider ships, this is the
 * one place a tenant's provider preference is decided — see
 * docs/plans/BYOK_MULTI_PROVIDER_EXTRACTION_PLAN.md §4.
 *
 * Feature-gating (ai_byok) and the platform-key fallback are the caller's
 * (FieldExtractorFactory's) concern, not this resolver's.
 */
final class ByokCredentialResolver
{
    public function forOwner(User $owner): ?AiCredential
    {
        $account = $owner->accountOwner();

        /** @var list<string> $priority */
        $priority = (array) config('invoicing.inbox.extraction.provider_priority', ['anthropic']);

        foreach ($priority as $providerValue) {
            $provider = AiProvider::tryFrom($providerValue);

            if ($provider === null) {
                continue;
            }

            $credential = AiCredential::query()
                ->where('user_id', $account->id)
                ->where('provider', $provider->value)
                ->first();

            if ($credential !== null) {
                return $credential;
            }
        }

        return null;
    }
}
