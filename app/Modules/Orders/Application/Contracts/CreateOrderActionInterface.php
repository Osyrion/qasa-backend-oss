<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use App\Modules\Orders\Application\DTOs\OrderData;
use App\Modules\Orders\Domain\ValueObjects\OrderItemDraft;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use App\Modules\Shared\Exceptions\DomainException;
use Throwable;

/**
 * Creating an order from outside Orders.
 *
 * Returns the new order's id rather than the aggregate, and takes its opening
 * lines as drafts rather than letting the caller write `order_items` itself —
 * a converter that reached for `$order->items()` would be deciding how a line
 * rounds, which is the one thing that must have a single home. Orders' own
 * controller keeps the concrete action's execute(), which still returns the
 * model it legitimately holds.
 */
interface CreateOrderActionInterface
{
    /**
     * @param  list<OrderItemDraft>  $items  lines to create with the order
     * @return string the new order's id
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function create(
        OrderData $data,
        Account&ProvidesPlanEntitlements&ProvidesSupplierProfile $owner,
        array $items = [],
    ): string;
}
