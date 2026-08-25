<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Orders\Application\Contracts\OrderAuthorization;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Domain\Contracts\Actor;
use Illuminate\Support\Facades\Gate;

/**
 * Answers by asking OrderPolicy, so the rule has one home.
 */
final readonly class GateOrderAuthorization implements OrderAuthorization
{
    public function allowsCreate(Actor $actor): bool
    {
        return Gate::forUser($actor)->allows('create', Order::class);
    }

    public function allowsRead(Actor $actor, string $orderId): bool
    {
        $order = Order::query()->find($orderId);

        if (! $order instanceof Order) {
            return false;
        }

        return Gate::forUser($actor)->allows('view', $order);
    }
}
