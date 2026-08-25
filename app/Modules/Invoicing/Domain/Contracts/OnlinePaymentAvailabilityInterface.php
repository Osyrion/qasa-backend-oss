<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\PublicInvoice;

/**
 * Whether the public invoice page should offer a "pay online" button.
 * The OSS core binds AlwaysUnavailableOnlinePayment (always false) so the
 * button never appears without Stripe configured; the SaaS edition
 * (Integrations module) overrides this against the owner's Stripe Connect
 * account and plan feature — same null-object pattern as
 * ClientUsagePolicyInterface/PlanClientUsagePolicy.
 *
 * Takes the value rather than the model: the implementation ships in a
 * premium module the OSS generator deletes, so this signature is an edition
 * boundary as well as a module one — and a column renamed here would break it
 * silently, in a tree the core developer never builds.
 */
interface OnlinePaymentAvailabilityInterface
{
    public function isAvailableFor(PublicInvoice $invoice): bool;
}
