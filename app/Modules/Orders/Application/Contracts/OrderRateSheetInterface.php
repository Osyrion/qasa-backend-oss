<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use Carbon\CarbonInterface;

/**
 * Preloaded rate history for one billing scope, narrowed to the single
 * question invoicing asks of it: what hourly rate applied on a work date.
 *
 * The OSS edition has no rate history at all (EmptyOrderRateSheet), so the
 * order's own rate column is the only rate. The Pricing module's RateSheet
 * implements this on top of its per-level rate resolution.
 */
interface OrderRateSheetInterface
{
    /**
     * Hourly rate effective on $date, or null when no rate history applies.
     */
    public function hourlyRateOn(CarbonInterface $date): ?float;
}
