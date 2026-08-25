<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

/**
 * `orders.rate` as the denormalised "currently effective" rate.
 *
 * The history lives in Pricing and the column lives here, which is exactly the
 * kind of split that ends up with one module writing into another's table.
 * It runs both ways and both ways are named: Orders tells Pricing that a rate
 * changed ({@see OrderRateRecorderInterface}), and Pricing tells Orders which
 * rate is now in force. Neither side touches the other's rows.
 *
 * Null clears it — a removed rate is recorded in the history as a tombstone
 * and the column follows.
 */
interface OrderRateCache
{
    public function storeEffectiveRate(string $orderId, ?float $rate): void;
}
