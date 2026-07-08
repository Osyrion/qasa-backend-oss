<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class InvoiceItemData extends Data
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

        #[Nullable]
        public readonly ?string $order_item_id = null,

        #[Nullable]
        public readonly ?string $time_entry_id = null,

        #[Nullable]
        public readonly ?string $price_list_item_id = null,
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
            'order_item_id' => ['nullable', 'uuid', 'exists:order_items,id'],
            'time_entry_id' => ['nullable', 'uuid', 'exists:time_entries,id'],
            'price_list_item_id' => ['nullable', 'uuid', 'exists:price_list_items,id'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            description: $request->string('description')->toString(),
            quantity: $request->float('quantity'),
            unit: $request->string('unit', 'ks')->toString(),
            unit_price: $request->float('unit_price'),
            vat_rate: $request->float('vat_rate'),
            sort_order: $request->integer('sort_order', 0),
            order_item_id: $request->filled('order_item_id') ? $request->string('order_item_id')->toString() : null,
            time_entry_id: $request->filled('time_entry_id') ? $request->string('time_entry_id')->toString() : null,
            price_list_item_id: $request->filled('price_list_item_id') ? $request->string('price_list_item_id')->toString() : null,
        );
    }
}
