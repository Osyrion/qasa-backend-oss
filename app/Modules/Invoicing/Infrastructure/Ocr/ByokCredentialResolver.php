<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Invoicing\Application\Contracts\ByokCredentialResolverInterface;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Domain\Models\AiCredential;
use App\Modules\Shared\Domain\Contracts\Account;

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
final class ByokCredentialResolver implements ByokCredentialResolverInterface
{
    public function forOwner(Account $owner): ?AiCredential
    {
        // accountOwnerId(), not accountOwner()->id: for a team member whose
        // owner row will not load the latter silently falls back to the
        // member itself, which would look up the wrong account's credential.
        $accountId = $owner->accountOwnerId();

        /** @var list<string> $priority */
        $priority = (array) config('invoicing.inbox.extraction.provider_priority', ['anthropic']);

        foreach ($priority as $providerValue) {
            $provider = AiProvider::tryFrom($providerValue);

            if ($provider === null) {
                continue;
            }

            $credential = AiCredential::query()
                ->where('user_id', $accountId)
                ->where('provider', $provider->value)
                ->first();

            if ($credential !== null) {
                return $credential;
            }
        }

        return null;
    }
}
