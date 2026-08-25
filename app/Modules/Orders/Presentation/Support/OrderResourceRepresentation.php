<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Support;

use App\Modules\Orders\Application\Contracts\OrderRepresentation;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Presentation\Resources\OrderResource;

/**
 * Renders the order through the resource Orders' own endpoints use, so an
 * order handed back by a conversion and one fetched directly cannot say
 * different things.
 */
final readonly class OrderResourceRepresentation implements OrderRepresentation
{
    public function forOrder(string $orderId): array
    {
        /** @var Order $order */
        $order = Order::query()->with('items')->findOrFail($orderId);

        return OrderResource::make($order)->resolve();
    }
}
