<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\DTOs;

use App\Modules\Orders\Domain\Enums\OrderItemType;
use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class OrderItemData extends Data
{
    public function __construct(
        public readonly OrderItemType $type,

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
            'quantity' => ['numeric', 'min:0.001'],
            'unit_price' => ['numeric', 'min:0'],
            'vat_rate' => ['numeric', 'min:0', 'max:100'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            type: OrderItemType::from($request->string('type')->toString()),
            description: $request->string('description')->toString(),
            quantity: $request->float('quantity'),
            unit: $request->string('unit')->toString(),
            unit_price: $request->float('unit_price'),
            vat_rate: $request->float('vat_rate'),
            sort_order: $request->integer('sort_order', 0),
        );
    }
}
