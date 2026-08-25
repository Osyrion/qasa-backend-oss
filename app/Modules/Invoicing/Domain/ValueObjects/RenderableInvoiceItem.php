<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

/**
 * One line of an issued document, as a renderer reads it.
 *
 * Every figure is what the document already stores. Recomputing a line total
 * from quantity × price is exactly how an export starts disagreeing with the
 * invoice it represents, and a golden test cannot catch it because it only
 * compares the export against itself.
 */
final readonly class RenderableInvoiceItem
{
    public function __construct(
        public string $description,
        public float $quantity,
        public string $unit,
        public float $unitPrice,
        public float $vatRate,
        public float $vatAmount,
        public float $totalExclVat,
        public float $totalInclVat,
    ) {}
}
