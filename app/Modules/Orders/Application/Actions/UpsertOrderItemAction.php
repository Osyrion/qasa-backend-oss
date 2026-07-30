<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientUsageGuardInterface;
use App\Modules\Orders\Application\DTOs\OrderItemData;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderItem;
use App\Modules\Shared\Enums\ItemUnit;
use App\Modules\Shared\Exceptions\DomainException;

class UpsertOrderItemAction
{
    public function __construct(
        private readonly ClientUsageGuardInterface $usageGuard,
    ) {}

    /**
     * @throws DomainException
     */
    public function execute(Order $order, OrderItemData $data, ?OrderItem $existing = null): OrderItem
    {
        if ($order->client !== null) {
            $this->usageGuard->ensureUsable($order->client);
        }

        $unit = ItemUnit::tryFrom($data->unit)->value ?? $data->unit;

        $attributes = [
            'type' => $data->type->value,
            'price_list_item_id' => $data->price_list_item_id,
            'description' => $data->description,
            'quantity' => $data->quantity,
            'unit' => $unit,
            'unit_price' => $data->unit_price,
            'vat_rate' => $data->vat_rate,
            'sort_order' => $data->sort_order,
        ];

        if ($existing !== null) {
            $existing->fill($attributes);
            $existing->recalculate()->save();

            return $existing->fresh() ?? $existing;
        }

        /** @var OrderItem $item */
        $item = $order->items()->make($attributes);
        $item->recalculate();
        $item->save();

        return $item;
    }
}
