<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaymentOrder',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'payer_snapshot', type: 'object', description: 'Frozen payer account (label, number, IBAN, BIC, currency)'),
        new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
        new OA\Property(property: 'due_date', type: 'string', format: 'date'),
        new OA\Property(property: 'constant_symbol', type: 'string', nullable: true),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'items_count', type: 'integer'),
        new OA\Property(property: 'total_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'marked_paid', type: 'boolean'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/PaymentOrderItem'), nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class PaymentOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'bank_account_id' => $this->resource->bank_account_id,
            'payer_snapshot' => $this->resource->payer_snapshot,
            'currency' => $this->resource->currency->value,
            'due_date' => $this->resource->due_date->toDateString(),
            'constant_symbol' => $this->resource->constant_symbol,
            'note' => $this->resource->note,
            'items_count' => $this->resource->items_count,
            'total_amount' => (float) $this->resource->total_amount,
            'marked_paid' => $this->resource->marked_paid,

            'items' => $this->when(
                $this->resource->relationLoaded('items'),
                fn () => PaymentOrderItemResource::collection($this->resource->items),
            ),

            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
