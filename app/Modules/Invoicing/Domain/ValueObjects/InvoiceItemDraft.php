<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Orders\Domain\ValueObjects\OrderItemDraft;

/**
 * A line another module wants a new invoice to start life with.
 *
 * Only what a caller supplies: the VAT amount and both totals are derived, and
 * deriving them is ours — a module that computed them itself would be a second
 * place rounding on a tax document is decided. Sibling of
 * {@see OrderItemDraft}, deliberately
 * the same shape.
 */
final readonly class InvoiceItemDraft
{
    public function __construct(
        public string $description,
        public float $quantity,
        /** Unit of measure; `invoice_items.unit` is NOT NULL, unlike an order line's. */
        public string $unit,
        public float $unitPrice,
        public float $vatRate,
        /** Where it prints; the caller's list order is what this means. */
        public int $sortOrder = 0,
    ) {}
}
