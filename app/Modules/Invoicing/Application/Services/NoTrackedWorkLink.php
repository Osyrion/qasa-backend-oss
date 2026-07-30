<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\TrackedWorkLinkInterface;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * OSS core default: nothing tracks work, so no invoice line is linked to an
 * order that way and the query is left untouched.
 */
final class NoTrackedWorkLink implements TrackedWorkLinkInterface
{
    /**
     * @param  Builder<InvoiceItem>  $query
     */
    public function orWhereLinkedToOrder(Builder $query, string $orderId): void
    {
        //
    }
}
