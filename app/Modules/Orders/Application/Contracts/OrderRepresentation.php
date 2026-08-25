<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The canonical JSON body for an order — the `#/components/schemas/Order`
 * shape, as returned by the orders endpoints.
 *
 * Same arrangement as Clients' ClientRepresentation: the shape is the
 * contract, the resource is one implementation of it, which is why this
 * interface lives in Application while what satisfies it lives in
 * Presentation. Keyed by id, because a module that has an order model to hand
 * us has already crossed the line this closes.
 */
interface OrderRepresentation
{
    /**
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException when the current account has no such order
     */
    public function forOrder(string $orderId): array;
}
