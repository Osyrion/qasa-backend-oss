<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Orders\Application\Contracts\OrderRateSheetInterface;
use Carbon\CarbonInterface;

/**
 * OSS core default: no rate history exists, so every work date falls back to
 * the order's own rate at the call site.
 */
final class EmptyOrderRateSheet implements OrderRateSheetInterface
{
    public function hourlyRateOn(CarbonInterface $date): ?float
    {
        return null;
    }
}
