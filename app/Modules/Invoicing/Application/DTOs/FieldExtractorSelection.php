<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use App\Modules\Invoicing\Domain\Contracts\InvoiceFieldExtractor;
use App\Modules\Invoicing\Domain\Models\AiCredential;

/**
 * FieldExtractorFactory's decision for one inbox item — flags describe *why*
 * regex was chosen when AI was the tier's default, so the job can annotate
 * suggestions for the FE (upsell / "your key is invalid" messaging).
 * $credential is set only on the BYOK path, so ProcessInboxItemJob can
 * invalidate it (verified_at/last_error) if the call turns out to fail with
 * an auth error.
 */
final readonly class FieldExtractorSelection
{
    public function __construct(
        public InvoiceFieldExtractor $extractor,
        public bool $quotaConsumed = false,
        public bool $quotaExhausted = false,
        public ?AiCredential $credential = null,
    ) {}
}
