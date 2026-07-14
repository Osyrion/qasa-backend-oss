<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderItem',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string', enum: ['service', 'product']),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity', type: 'number', format: 'float'),
        new OA\Property(property: 'unit', type: 'string', example: 'ks'),
        new OA\Property(property: 'unit_price', type: 'number', format: 'float'),
        new OA\Property(property: 'vat_rate', type: 'number', format: 'float'),
        new OA\Property(property: 'vat_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'total_excl_vat', type: 'number', format: 'float'),
        new OA\Property(property: 'total_incl_vat', type: 'number', format: 'float'),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type,
            'description' => $this->resource->description,
            'quantity' => (float) $this->resource->quantity,
            'unit' => $this->resource->unit,
            'unit_price' => (float) $this->resource->unit_price,
            'vat_rate' => (float) $this->resource->vat_rate,
            'vat_amount' => (float) $this->resource->vat_amount,
            'total_excl_vat' => (float) $this->resource->total_excl_vat,
            'total_incl_vat' => (float) $this->resource->total_incl_vat,
            'sort_order' => $this->resource->sort_order,
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
