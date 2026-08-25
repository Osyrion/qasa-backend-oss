<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;

/**
 * What one client was invoiced in one currency over a period, already summed.
 *
 * Bounded by how many clients an account has, not by how many documents it
 * has issued, which is what makes it safe to hand over as rows. Credit notes
 * carry a negative total, so an amount can come back at or below zero and the
 * caller decides what that means.
 *
 * The client is never null: `invoices.client_id` is NOT NULL, unlike a
 * supplier invoice's ({@see OutstandingDocument}).
 */
final readonly class ClientTurnover
{
    public function __construct(
        public string $clientId,
        public Currency $currency,
        public float $amount,
    ) {}
}
