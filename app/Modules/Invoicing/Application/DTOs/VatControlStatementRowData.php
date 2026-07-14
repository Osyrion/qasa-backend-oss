<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

/**
 * A single per-document VAT control statement row — one row per document per
 * VAT rate bucket (SK KV DPH A.1/A.2/B.1/B.2/C.1, CZ kontrolní hlášení
 * A.1/A.4/B.1/B.2 equivalents).
 */
final readonly class VatControlStatementRowData
{
    public function __construct(
        public string $documentNumber,
        public string $date,
        public string $partnerName,
        public ?string $partnerTaxId,
        public float $rate,
        public float $base,
        public float $vat,
        public ?string $relatedDocumentNumber = null,
    ) {}
}
