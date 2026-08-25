<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Orders\Application\Contracts\OrderRateResolverInterface;
use App\Modules\Orders\Application\Contracts\OrderRateSheetInterface;
use App\Modules\Shared\Domain\Contracts\Account;

/**
 * OSS core default: rate history is a Pricing (premium) feature, so the
 * resolved sheet is always empty.
 */
final class NoRateHistoryResolver implements OrderRateResolverInterface
{
    public function sheetFor(Account $user, ?string $clientId = null, ?string $orderId = null): OrderRateSheetInterface
    {
        return new EmptyOrderRateSheet;
    }
}
