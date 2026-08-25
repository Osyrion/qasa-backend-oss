<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use App\Modules\Orders\Domain\ValueObjects\OrderRateChange;

/**
 * Records that an order's hourly rate changed, so past work keeps the rate
 * it was done under.
 *
 * OSS keeps no rate history — the order's rate column simply holds the
 * current value and NullOrderRateRecorder does nothing. The Pricing module
 * rebinds this to RecordOrderRateChangeAction, which writes a Rate row.
 *
 * It takes a value rather than the `Order` model even though the contract is
 * Orders' own: the implementation is not, and a signature naming the aggregate
 * makes the implementing module depend on it. The reverse direction —
 * "the effective rate is now this" — is {@see OrderRateCache}.
 */
interface OrderRateRecorderInterface
{
    public function record(OrderRateChange $change): void;
}
