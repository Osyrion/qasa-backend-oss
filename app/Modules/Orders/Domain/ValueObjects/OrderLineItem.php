<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\ValueObjects;

/**
 * One agreed line of an order, as the invoice generator reads it.
 *
 * The stored totals are left out on purpose: an invoice line recomputes them
 * anyway — possibly after a currency conversion — so handing them over would
 * publish two numbers where only one is ever used.
 */
final readonly class OrderLineItem
{
    public function __construct(
        public string $id,
        /** The price-list entry this line came from, when it came from one. */
        public ?string $priceListItemId,
        public string $description,
        public float $quantity,
        public ?string $unit,
        public float $unitPrice,
        public float $vatRate,
    ) {}
}
