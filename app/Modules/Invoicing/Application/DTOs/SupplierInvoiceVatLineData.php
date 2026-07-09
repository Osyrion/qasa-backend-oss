<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use Spatie\LaravelData\Data;

class SupplierInvoiceVatLineData extends Data
{
    public function __construct(
        public readonly float $vat_rate,
        public readonly float $base,
        public readonly float $vat_amount,
        public readonly int $sort_order = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'base' => ['required', 'numeric', 'min:0'],
            'vat_amount' => ['required', 'numeric', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function fromArray(array $line): self
    {
        return new self(
            vat_rate: (float) $line['vat_rate'],
            base: (float) $line['base'],
            vat_amount: (float) $line['vat_amount'],
            sort_order: (int) ($line['sort_order'] ?? 0),
        );
    }
}
