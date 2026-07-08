<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\Actions;

use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Application\Contracts\RecordOrderRateChangeActionInterface;
use App\Modules\Pricing\Domain\Enums\RateLevel;
use App\Modules\Pricing\Domain\Models\Rate;

/**
 * Write-through from orders.rate to the append-only rates history.
 * A removed rate is recorded as a tombstone (rate = null) so resolution
 * falls back to the client/global level from today on, while work done
 * before today keeps the old order rate.
 */
class RecordOrderRateChangeAction implements RecordOrderRateChangeActionInterface
{
    public function execute(Order $order, ?float $rate): Rate
    {
        return Rate::withoutGlobalScope('user')->updateOrCreate(
            [
                'user_id' => $order->user_id,
                'level' => RateLevel::Order->value,
                'client_id' => null,
                'order_id' => $order->id,
                'valid_from' => today(),
            ],
            [
                'rate' => $rate,
                'currency' => $order->currency?->value,
                'note' => 'Zapísané zo zákazky',
            ],
        );
    }
}
