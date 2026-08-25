<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Orders\Application\Contracts\OrderLookup;
use App\Modules\Orders\Application\Contracts\OrderRateCache;
use App\Modules\Orders\Domain\Enums\OrderStatus;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderItem;
use App\Modules\Orders\Domain\ValueObjects\OrderLineItem;
use App\Modules\Orders\Domain\ValueObjects\OrderSummary;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only place outside Orders' own code that turns an order row into
 * something another module may hold.
 *
 * The single-order reads leave the account scope on deliberately: a summary
 * for an order the caller's account cannot see comes back null, which is the
 * same answer the consumer would have got from a scoped query of its own — and
 * the same 404 five controllers used to write by hand.
 *
 * The account-wide reads take the owner as an argument instead, because their
 * callers are not always inside a request that has one.
 */
final readonly class EloquentOrderLookup implements OrderLookup, OrderRateCache
{
    public function summary(string $orderId): ?OrderSummary
    {
        return $this->summaryOf(Order::query()->with('client')->find($orderId));
    }

    public function summaryForAccount(string $orderId, string $ownerId): ?OrderSummary
    {
        return $this->summaryOf(
            Order::withoutGlobalScope('user')
                ->where('user_id', $ownerId)
                ->with('client')
                ->find($orderId),
        );
    }

    public function itemsFor(string $orderId): array
    {
        return array_values(OrderItem::query()
            ->where('order_id', $orderId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (OrderItem $item): OrderLineItem => new OrderLineItem(
                id: $item->id,
                priceListItemId: $item->price_list_item_id,
                description: $item->description,
                quantity: (float) $item->quantity,
                unit: $item->unit,
                unitPrice: (float) $item->unit_price,
                vatRate: (float) $item->vat_rate,
            ))
            ->all());
    }

    public function owns(string $orderId, ?string $orderItemId = null): bool
    {
        $order = Order::query()->find($orderId);

        if (! $order instanceof Order) {
            return false;
        }

        // Through the relation rather than a bare `order_items` query: the
        // caller is asking whether the line belongs to *this* order, and the
        // relation is what says so.
        return $orderItemId === null || $order->items()->whereKey($orderItemId)->exists();
    }

    public function summariesFor(iterable $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(
            is_array($orderIds) ? $orderIds : iterator_to_array($orderIds),
            static fn (?string $id): bool => $id !== null && $id !== '',
        )));

        if ($ids === []) {
            return [];
        }

        // No account filter and no scope: the caller already narrowed the set
        // to ids it read out of its own scoped rows, and a report over a past
        // period still has to name an order that has since been archived.
        /** @var array<string, OrderSummary> $summaries */
        $summaries = $this->withClient(Order::withoutGlobalScope('user')->whereIn('id', $ids))
            ->get()
            ->mapWithKeys(fn (Order $order): array => [$order->id => $this->summarise($order)])
            ->all();

        return $summaries;
    }

    public function deadlinesBetween(string $ownerId, ?string $from, ?string $to): array
    {
        $query = Order::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->whereIn('status', [OrderStatus::Active->value, OrderStatus::Paused->value])
            ->whereNotNull('deadline');

        if ($from !== null) {
            $query->where('deadline', '>=', $from);
        }

        if ($to !== null) {
            $query->where('deadline', '<=', $to);
        }

        return array_values($this->withClient($query)
            ->get()
            ->map(fn (Order $order): OrderSummary => $this->summarise($order))
            ->all());
    }

    public function storeEffectiveRate(string $orderId, ?float $rate): void
    {
        $order = Order::withoutGlobalScope('user')->find($orderId);

        // forceFill, as the write-through always did: `rate` is derived from
        // the history rather than submitted, so it is not a fillable input.
        $order?->forceFill(['rate' => $rate])->save();
    }

    private function summaryOf(?Order $order): ?OrderSummary
    {
        return $order instanceof Order ? $this->summarise($order) : null;
    }

    private function summarise(Order $order): OrderSummary
    {
        return new OrderSummary(
            id: $order->id,
            name: $order->name,
            clientId: $order->client_id,
            clientName: $order->client?->display_name,
            color: $order->color,
            deadline: $order->deadline,
            currency: $order->effectiveCurrency(),
            rate: $order->rate !== null ? (float) $order->rate : null,
        );
    }

    /**
     * Only the columns `display_name` is built from. Without the relation
     * every summary is one more query; without the column list it is the whole
     * client row per order.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function withClient(Builder $query): Builder
    {
        return $query->with('client:id,client_type,title,name,surname,company_name');
    }
}
