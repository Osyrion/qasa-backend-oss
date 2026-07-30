<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use App\Modules\Orders\Domain\Models\Order;

/**
 * Records that an order's hourly rate changed, so past work keeps the rate
 * it was done under.
 *
 * OSS keeps no rate history — the order's rate column simply holds the
 * current value and NullOrderRateRecorder does nothing. The Pricing module
 * rebinds this to RecordOrderRateChangeAction, which writes a Rate row.
 */
interface OrderRateRecorderInterface
{
    public function record(Order $order, ?float $rate): void;
}
