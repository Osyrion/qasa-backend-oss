<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Orders\Application\Contracts\BillableWorkProviderInterface;

/**
 * OSS core default: time tracking is a premium feature, so no order ever has
 * tracked work to bill.
 */
final class NoBillableWorkProvider implements BillableWorkProviderInterface
{
    /**
     * @return list<never>
     */
    public function billableWorkFor(string $orderId): array
    {
        return [];
    }

    public function markInvoiced(string $workId): void
    {
        //
    }

    public function markUninvoiced(string $workId): void
    {
        //
    }

    public function isOrderItemInvoiced(string $orderItemId): bool
    {
        return false;
    }
}
