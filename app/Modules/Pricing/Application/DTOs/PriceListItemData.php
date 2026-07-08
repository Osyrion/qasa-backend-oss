<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class PriceListItemData extends Data
{
    public function __construct(
        #[Max(255)]
        public readonly string $name,

        #[Nullable]
        public readonly ?string $description,

        #[Max(20)]
        public readonly string $unit,

        public readonly float $unit_price,

        public readonly float $vat_rate,

        public readonly bool $is_active = true,

        public readonly int $sort_order = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit' => ['required', 'string', 'max:20'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'vat_rate' => ['numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            description: $request->filled('description') ? $request->string('description')->toString() : null,
            unit: $request->string('unit', 'ks')->toString(),
            unit_price: $request->float('unit_price'),
            vat_rate: $request->float('vat_rate'),
            is_active: $request->boolean('is_active', true),
            sort_order: $request->integer('sort_order', 0),
        );
    }
}
