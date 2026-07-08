<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PriceList',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', example: 'Cenník SK'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD'], nullable: true),
        new OA\Property(property: 'country', type: 'string', example: 'SK', nullable: true),
        new OA\Property(property: 'is_default', type: 'boolean'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/PriceListItem'), nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class PriceListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'currency' => $this->resource->currency?->value,
            'country' => $this->resource->country,
            'is_default' => $this->resource->is_default,

            'items' => $this->when(
                $this->resource->relationLoaded('items'),
                fn (): AnonymousResourceCollection => PriceListItemResource::collection($this->resource->items),
            ),

            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
