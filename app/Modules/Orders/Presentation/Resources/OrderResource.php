<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Resources;

use App\Modules\Clients\Presentation\Resources\ClientResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Order',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'color', type: 'string', example: '#3B82F6', nullable: true),
        new OA\Property(property: 'readme', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'paused', 'completed', 'archived']),
        new OA\Property(property: 'billing_type', type: 'string', nullable: true, enum: ['hourly', 'daily', 'monthly', 'fixed_per_item', 'mixed']),
        new OA\Property(property: 'rate', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'currency', type: 'string', nullable: true, enum: ['CZK', 'EUR', 'USD']),
        new OA\Property(property: 'effective_currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
        new OA\Property(property: 'estimated_hours', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'estimated_price', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'deadline', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_personal', type: 'boolean'),
        new OA\Property(property: 'is_billable', type: 'boolean'),
        new OA\Property(property: 'client', ref: '#/components/schemas/Client', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderItem'), nullable: true),
        new OA\Property(property: 'notes', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderNote'), nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'color' => $this->resource->color,
            'readme' => $this->resource->readme,
            'status' => $this->resource->status,
            'billing_type' => $this->resource->billing_type?->value,
            'rate' => $this->resource->rate !== null ? (float) $this->resource->rate : null,
            'currency' => $this->resource->currency?->value,
            'effective_currency' => $this->resource->effectiveCurrency()->value,
            'estimated_hours' => $this->resource->estimated_hours !== null ? (float) $this->resource->estimated_hours : null,
            'estimated_price' => $this->resource->estimated_price !== null ? (float) $this->resource->estimated_price : null,
            'deadline' => $this->resource->deadline?->toDateString(),
            'is_personal' => $this->resource->isPersonal(),
            'is_billable' => $this->resource->isBillable(),

            // Conditionally loaded relations
            'client' => $this->when(
                $this->resource->relationLoaded('client') && $this->resource->client !== null,
                fn (): ClientResource => ClientResource::make($this->resource->client),
            ),

            'items' => $this->when(
                $this->resource->relationLoaded('items'),
                fn () => OrderItemResource::collection($this->resource->items),
            ),

            'notes' => $this->when(
                $this->resource->relationLoaded('notes'),
                fn () => OrderNoteResource::collection($this->resource->notes),
            ),

            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
