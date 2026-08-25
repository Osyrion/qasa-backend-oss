<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use App\Modules\Shared\Domain\Contracts\Actor;

/**
 * May this person create an order?
 *
 * Two abilities, because two are asked: converting a quote produces an order,
 * so it needs `orders.manage` on top of the invoicing permission the quote
 * itself checked, and generating an invoice from an order needs read access to
 * that order.
 *
 * allowsRead() answering false does not distinguish "not allowed" from "not
 * there" — a caller that needs to 404 first asks {@see OrderLookup}, whose
 * account scope makes a foreign order invisible, and only then asks this.
 */
interface OrderAuthorization
{
    public function allowsCreate(Actor $actor): bool;

    public function allowsRead(Actor $actor, string $orderId): bool;
}
