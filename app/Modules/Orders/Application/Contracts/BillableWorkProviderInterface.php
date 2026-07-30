<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use App\Modules\Orders\Application\DTOs\BillableWork;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderItem;

/**
 * Tracked work that can be billed onto an invoice.
 *
 * OSS binds NoBillableWorkProvider — without the TimeTracking module there is
 * no tracked work, so every method is empty or a no-op. TimeTracking rebinds
 * this to the TimeEntry-backed implementation.
 *
 * Work is addressed by opaque string id (invoice_items.time_entry_id holds
 * it); callers must not assume what it points at.
 */
interface BillableWorkProviderInterface
{
    /**
     * Billable, not-yet-invoiced work logged against the order.
     *
     * @return list<BillableWork>
     */
    public function billableWorkFor(Order $order): array;

    public function markInvoiced(string $workId): void;

    public function markUninvoiced(string $workId): void;

    /**
     * Whether the work logged against this order item has already been
     * invoiced — such an item must not be edited or deleted.
     */
    public function isOrderItemInvoiced(OrderItem $item): bool;
}
