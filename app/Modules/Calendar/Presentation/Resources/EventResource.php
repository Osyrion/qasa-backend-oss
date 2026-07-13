<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Event',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'color', type: 'string', nullable: true, example: '#3B82F6'),
        new OA\Property(property: 'effective_color', type: 'string', nullable: true, description: 'color, falling back to the linked order\'s color when the event has none'),
        new OA\Property(property: 'is_all_day', type: 'boolean'),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', description: 'Exclusive; midnight stored as next-day 00:00'),
        new OA\Property(property: 'source', type: 'string', enum: ['manual', 'csv_import', 'ics_import']),
        new OA\Property(property: 'order_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(
            property: 'order',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'color', type: 'string', nullable: true),
                new OA\Property(property: 'client_display_name', type: 'string', nullable: true),
            ]
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class EventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource->relationLoaded('order') ? $this->resource->order : null;

        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'location' => $this->resource->location,
            'color' => $this->resource->color,
            'effective_color' => $this->resource->color ?? $order?->color,
            'is_all_day' => $this->resource->is_all_day,
            'starts_at' => $this->resource->starts_at->toISOString(),
            'ends_at' => $this->resource->ends_at->toISOString(),
            'source' => $this->resource->source->value,
            'order_id' => $this->resource->order_id,
            'order' => $order === null ? null : [
                'id' => $order->id,
                'name' => $order->name,
                'color' => $order->color,
                'client_display_name' => $order->client?->display_name,
            ],
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
