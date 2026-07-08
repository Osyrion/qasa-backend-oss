<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PriceListItem',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'price_list_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', example: 'Konzultácia'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'unit', type: 'string', example: 'hod'),
        new OA\Property(property: 'unit_price', type: 'number', format: 'float', example: 50),
        new OA\Property(property: 'vat_rate', type: 'number', format: 'float', example: 20),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class PriceListItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'price_list_id' => $this->resource->price_list_id,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'unit' => $this->resource->unit,
            'unit_price' => (float) $this->resource->unit_price,
            'vat_rate' => (float) $this->resource->vat_rate,
            'is_active' => $this->resource->is_active,
            'sort_order' => $this->resource->sort_order,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
