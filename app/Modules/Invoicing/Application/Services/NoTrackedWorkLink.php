<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\TrackedWorkLinkInterface;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * OSS core default: nothing tracks work, so no invoice line is linked to an
 * order that way and the query is left untouched.
 */
final class NoTrackedWorkLink implements TrackedWorkLinkInterface
{
    public function workIdsForOrder(string $orderId): ?Builder
    {
        return null;
    }
}
