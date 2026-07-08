<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Rate',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'level', type: 'string', enum: ['user', 'client', 'order']),
        new OA\Property(property: 'client_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'order_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'rate', type: 'number', format: 'float', example: 45.5),
        new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD'], nullable: true),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
        new OA\Property(property: 'is_deletable', type: 'boolean', description: 'Rates effective from today or later may still be deleted'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class RateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'level' => $this->resource->level->value,
            'client_id' => $this->resource->client_id,
            'order_id' => $this->resource->order_id,
            'rate' => $this->resource->rate !== null ? (float) $this->resource->rate : null,
            'currency' => $this->resource->currency?->value,
            'valid_from' => $this->resource->valid_from->toDateString(),
            'is_deletable' => $this->resource->isDeletable(),
            'note' => $this->resource->note,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
