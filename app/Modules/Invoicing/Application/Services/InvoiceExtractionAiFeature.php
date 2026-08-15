<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Application\Contracts\AiFeatureDescriptor;
use App\Modules\Shared\Application\DTOs\AiFeatureDisclosure;

/**
 * The transparency entry for inbox OCR extraction — the one AI capability
 * that ships in both editions, and the one that sends the most sensitive
 * payload (a whole supplier invoice, third-party personal data included, see
 * docs/legal/SUBPROCESSORS.md).
 *
 * `enabled` mirrors FieldExtractorFactory's decision tree closely enough to
 * be honest about whether a document would actually reach a model: the
 * driver toggle and the account switch. The remaining branches (plan
 * feature, key present, quota left) are reported through the account-level
 * provider/model block instead of duplicating the whole tree here — this is
 * a notice, not a second implementation of the routing.
 */
final readonly class InvoiceExtractionAiFeature implements AiFeatureDescriptor
{
    public function disclosureFor(User $owner): AiFeatureDisclosure
    {
        $account = $owner->accountOwner();

        $enabled = (string) config('invoicing.inbox.extraction.driver', 'regex') !== 'regex'
            && $account->ai_extraction_enabled
            && ($account->hasFeature('ai_extraction') || $account->hasFeature('ai_byok'));

        return new AiFeatureDisclosure(
            key: 'invoice_extraction',
            name: __('ai.features.invoice_extraction.name'),
            purpose: __('ai.features.invoice_extraction.purpose'),
            data_sent: __('ai.features.invoice_extraction.data_sent'),
            output: 'suggestion',
            opt_out: __('ai.features.invoice_extraction.opt_out'),
            enabled: $enabled,
        );
    }
}
