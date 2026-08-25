<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Payments;

use App\Modules\Invoicing\Domain\Contracts\OnlinePaymentAvailabilityInterface;
use App\Modules\Invoicing\Domain\ValueObjects\PublicInvoice;

/**
 * OSS core default — no Stripe Connect, the "pay online" button never shows.
 */
final class AlwaysUnavailableOnlinePayment implements OnlinePaymentAvailabilityInterface
{
    public function isAvailableFor(PublicInvoice $invoice): bool
    {
        return false;
    }
}
