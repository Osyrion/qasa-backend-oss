<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Contracts;

use App\Modules\Invoicing\Domain\Models\Invoice;

/**
 * Whether the public invoice page should offer a "pay online" button.
 * The OSS core binds AlwaysUnavailableOnlinePayment (always false) so the
 * button never appears without Stripe configured; the SaaS edition
 * (Integrations module) overrides this against the owner's Stripe Connect
 * account and plan feature — same null-object pattern as
 * ClientUsagePolicyInterface/FreeTierClientUsagePolicy.
 */
interface OnlinePaymentAvailabilityInterface
{
    public function isAvailableFor(Invoice $invoice): bool;
}
