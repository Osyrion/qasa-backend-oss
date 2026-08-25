<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\ValueObjects;

/**
 * A line another module wants an order to start life with.
 *
 * Only the fields a caller supplies: the totals and the VAT amount are
 * derived, and deriving them is ours — a converter that computed them itself
 * would be a second place rounding is decided.
 */
final readonly class OrderItemDraft
{
    public function __construct(
        public string $description,
        public float $quantity,
        public ?string $unit,
        public float $unitPrice,
        public float $vatRate,
        public int $sortOrder,
        /** Matches order_items.type; 'service' unless a caller knows better. */
        public string $type = 'service',
    ) {}
}
