<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InvoiceItem',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity', type: 'number', format: 'float'),
        new OA\Property(property: 'unit', type: 'string', example: 'ks'),
        new OA\Property(property: 'unit_price', type: 'number', format: 'float'),
        new OA\Property(property: 'vat_rate', type: 'number', format: 'float'),
        new OA\Property(property: 'vat_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'total_excl_vat', type: 'number', format: 'float'),
        new OA\Property(property: 'total_incl_vat', type: 'number', format: 'float'),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'is_from_order', type: 'boolean'),
        new OA\Property(property: 'is_from_time', type: 'boolean'),
        new OA\Property(property: 'is_manual', type: 'boolean'),
        new OA\Property(property: 'order_item_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'time_entry_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'price_list_item_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
class InvoiceItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'description' => $this->resource->description,
            'quantity' => (float) $this->resource->quantity,
            'unit' => $this->resource->unit,
            'unit_price' => (float) $this->resource->unit_price,
            'vat_rate' => (float) $this->resource->vat_rate,
            'vat_amount' => (float) $this->resource->vat_amount,
            'total_excl_vat' => (float) $this->resource->total_excl_vat,
            'total_incl_vat' => (float) $this->resource->total_incl_vat,
            'sort_order' => $this->resource->sort_order,
            'is_from_order' => $this->resource->isFromOrderItem(),
            'is_from_time' => $this->resource->isFromTimeEntry(),
            'is_manual' => $this->resource->isManual(),
            'order_item_id' => $this->resource->order_item_id,
            'time_entry_id' => $this->resource->time_entry_id,
            'price_list_item_id' => $this->resource->price_list_item_id,
        ];
    }
}
