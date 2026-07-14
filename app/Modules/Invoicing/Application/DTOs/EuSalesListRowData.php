<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

/**
 * One row of the EU sales list (súhrnný výkaz) report: a client's EU VAT ID,
 * the calendar month, and the total value of intra-EU reverse-charged
 * supplies to it. Code 3 = services (the only supply kind this app models —
 * goods/triangulation codes 1/2 are out of scope).
 */
final readonly class EuSalesListRowData
{
    public function __construct(
        public string $period,
        public string $vatId,
        public string $clientName,
        public float $amount,
        public int $code = 3,
    ) {}
}
