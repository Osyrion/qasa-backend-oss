<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class RecurringTemplateItemData extends Data
{
    public function __construct(
        #[Max(500)]
        public readonly string $description,

        public readonly float $quantity,

        #[Max(20)]
        public readonly string $unit,

        public readonly float $unit_price,

        public readonly float $vat_rate,

        public readonly int $sort_order = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:500'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit' => ['sometimes', 'string', 'max:20'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'vat_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function fromArray(array $item): self
    {
        return new self(
            description: (string) $item['description'],
            quantity: (float) $item['quantity'],
            unit: (string) ($item['unit'] ?? 'ks'),
            unit_price: (float) $item['unit_price'],
            vat_rate: (float) ($item['vat_rate'] ?? 0),
            sort_order: (int) ($item['sort_order'] ?? 0),
        );
    }
}
