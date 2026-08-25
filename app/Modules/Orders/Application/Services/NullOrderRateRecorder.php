<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Orders\Application\Contracts\OrderRateRecorderInterface;
use App\Modules\Orders\Domain\ValueObjects\OrderRateChange;

/**
 * OSS core default: the order's rate column already carries the current
 * value, and without the Pricing module there is nowhere to keep history.
 */
final class NullOrderRateRecorder implements OrderRateRecorderInterface
{
    public function record(OrderRateChange $change): void
    {
        //
    }
}
