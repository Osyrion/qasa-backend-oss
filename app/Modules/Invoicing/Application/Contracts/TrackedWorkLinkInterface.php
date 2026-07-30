<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * An invoice line can belong to an order two ways: through the order item it
 * bills, or through the tracked work it bills. The second link only exists
 * when a module provides tracked work, so filtering on it is delegated here.
 *
 * OSS binds NoTrackedWorkLink (invoice_items.time_entry_id is always null
 * there, so the clause would match nothing anyway).
 */
interface TrackedWorkLinkInterface
{
    /**
     * Widens an invoice-item query to also match items billed from work
     * tracked against the given order.
     *
     * @param  Builder<InvoiceItem>  $query
     */
    public function orWhereLinkedToOrder(Builder $query, string $orderId): void;
}
