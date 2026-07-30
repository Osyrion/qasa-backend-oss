<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Application\Contracts\OrderRateResolverInterface;
use App\Modules\Orders\Application\Contracts\OrderRateSheetInterface;
use App\Modules\Orders\Domain\Models\Order;

/**
 * OSS core default: rate history is a Pricing (premium) feature, so the
 * resolved sheet is always empty.
 */
final class NoRateHistoryResolver implements OrderRateResolverInterface
{
    public function sheetFor(User $user, ?Client $client = null, ?Order $order = null): OrderRateSheetInterface
    {
        return new EmptyOrderRateSheet;
    }
}
