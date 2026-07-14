<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use App\Modules\Invoicing\Domain\Rules\VatRateInCatalog;
use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class QuoteItemData extends Data
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
     * @param  string|null  $userId  Validates vat_rate against the tenant's catalog when given.
     * @return array<string, mixed>
     */
    public static function rules(?string $userId = null, ?string $country = null, ?string $onDate = null): array
    {
        return [
            'description' => ['required', 'string', 'max:500'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit' => ['sometimes', 'string', 'max:20'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'vat_rate' => [
                'sometimes', 'numeric', 'min:0', 'max:100',
                ...($userId !== null && $country !== null ? [new VatRateInCatalog($userId, $country, $onDate)] : []),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
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
        );
    }
}
