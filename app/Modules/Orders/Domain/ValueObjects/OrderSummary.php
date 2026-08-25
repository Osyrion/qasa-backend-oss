<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

/**
 * An order as another module is allowed to read it.
 *
 * Six modules point at an order without owning one: a time entry is booked
 * against it, a trip is driven for it, a rate is agreed for it, a deadline
 * shows up in the calendar. Almost none of them needs more than "does this
 * exist, whose is it, and what is it called" — and taking the `Order` model to
 * ask that publishes Orders' database rather than an API. Same move
 * `InvoiceSummary` made, and this is deliberately its sibling in shape.
 *
 * Only the fields something reads today. A summary that mirrors the table is
 * the model again with extra steps.
 */
final readonly class OrderSummary
{
    public function __construct(
        public string $id,
        public string $name,
        /** Null for a personal order — one with no client, which may not be invoiced or rated. */
        public ?string $clientId,
        /** The client's name as it stands today; null for a personal order. */
        public ?string $clientName,
        /** Hex colour the order is drawn in, for a calendar or a list. */
        public ?string $color,
        public ?Carbon $deadline,
        /**
         * The currency work on this order is billed in — the order's own, else
         * the client's, else the account's default. Resolved here because the
         * fallback chain is Orders' business, not the caller's.
         */
        public Currency $currency,
        /** The order's default rate per billing unit, when it has one. */
        public ?float $rate,
    ) {}

    /** An order with no client: personal work, never invoiced and never rated. */
    public function isPersonal(): bool
    {
        return $this->clientId === null;
    }
}
