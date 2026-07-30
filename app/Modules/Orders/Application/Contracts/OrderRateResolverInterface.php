<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Models\Order;

/**
 * Loads the rate history for a billing scope in one query, so many time
 * entries can be priced without a query each.
 *
 * OSS binds NoRateHistoryResolver; the Pricing module rebinds this to its
 * RateResolver via bootstrap provider ordering (Orders registers before
 * Pricing).
 */
interface OrderRateResolverInterface
{
    public function sheetFor(User $user, ?Client $client = null, ?Order $order = null): OrderRateSheetInterface;
}
