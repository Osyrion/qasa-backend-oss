<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

/**
 * A cumulative (per-rate, not per-document) VAT control statement row — CZ
 * kontrolní hlášení A.5/B.3 and SK KV DPH B.3 "súhrnné údaje".
 */
final readonly class VatControlStatementSummaryRowData
{
    public function __construct(
        public float $rate,
        public float $base,
        public float $vat,
    ) {}
}
