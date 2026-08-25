<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use App\Modules\Orders\Domain\ValueObjects\OrderLineItem;
use App\Modules\Orders\Domain\ValueObjects\OrderSummary;

/**
 * Reading an order from outside Orders.
 *
 * The counterpart to {@see OrderSummary}: a consumer that has an id gets the
 * value, never the model. Tenancy is the implementation's problem — the
 * summary comes back null for an order the current account cannot see, which
 * is the same answer a scoped query would have given, and the same 404 the
 * hand-written `Order::query()->findOrFail()` used to produce.
 */
interface OrderLookup
{
    public function summary(string $orderId): ?OrderSummary;

    /**
     * As summary(), but for a caller that is not inside a request — a console
     * command or a queued job, where the global scope is a no-op and the
     * account has to be named.
     */
    public function summaryForAccount(string $orderId, string $ownerId): ?OrderSummary;

    /**
     * Whether the current account owns this order — and, when a line id is
     * given, whether that line is on it.
     *
     * The pair of questions five controllers used to ask as two scoped
     * `findOrFail()` calls. Returning a bool rather than throwing keeps the
     * 404 where a reader can see it, and folding the line into the same call
     * matches what those callers actually wanted: a foreign key validated, not
     * the order's contents published.
     */
    public function owns(string $orderId, ?string $orderItemId = null): bool;

    /**
     * The order's agreed lines, in the order they are printed.
     *
     * The invoice generator is the one caller, and it needs every line's own
     * numbers rather than a total — it may reprice each of them into another
     * currency on the way onto the document.
     *
     * @return list<OrderLineItem>
     */
    public function itemsFor(string $orderId): array;

    /**
     * Print who these order ids are — the report shape.
     *
     * A statistics table groups tracked hours by order and then needs a name
     * and a client against each id it ended up with. Ids it does not
     * recognise are simply absent from the result, the same way
     * `ClientDirectory::profilesFor()` behaves.
     *
     * @param  iterable<int, string|null>  $orderIds
     * @return array<string, OrderSummary> keyed by order id
     */
    public function summariesFor(iterable $orderIds): array;

    /**
     * Orders with a deadline in the window, for a calendar that draws them
     * alongside real events.
     *
     * A set crosses here, and it is bounded the way `openForMatching()` is
     * bounded in Invoicing: one account's open orders over a range somebody is
     * looking at, printed one at a time rather than summed.
     *
     * @param  string|null  $from  Y-m-d, inclusive; null for no lower bound
     * @param  string|null  $to  Y-m-d, inclusive; null for no upper bound
     * @return list<OrderSummary> only orders that are active or paused
     */
    public function deadlinesBetween(string $ownerId, ?string $from, ?string $to): array;
}
