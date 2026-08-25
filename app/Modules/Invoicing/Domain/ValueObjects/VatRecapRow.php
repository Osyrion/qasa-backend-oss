<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

final readonly class VatRecapRow
{
    public function __construct(
        public float $rate,
        public float $base,
        public float $vat,
        public float $total,
    ) {}
}
