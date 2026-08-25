<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * An invoice line can belong to an order two ways: through the order item it
 * bills, or through the tracked work it bills. The second link only exists
 * when a module provides tracked work, so answering it is delegated here.
 *
 * The answer is a **subquery, not a builder to bolt a clause onto**. The
 * earlier shape handed the implementor our own `Builder<InvoiceItem>` and
 * asked it to add an `orWhereIn` — which is a live query handle on our table,
 * and worse than the type hint it came with. Now each side names only its own
 * model: they select the ids, we decide what to do with them.
 *
 * OSS binds NoTrackedWorkLink (invoice_items.time_entry_id is always null
 * there, so the clause would match nothing anyway).
 */
interface TrackedWorkLinkInterface
{
    /**
     * A query selecting the ids of work tracked against this order — the
     * values `invoice_items.time_entry_id` may hold for it.
     *
     * Returns a subquery rather than a list on purpose: a long-running order
     * can carry thousands of entries, and none of them need to travel into
     * PHP just to be compared against a column.
     *
     * Null when nothing in this edition tracks work.
     */
    public function workIdsForOrder(string $orderId): ?Builder;
}
