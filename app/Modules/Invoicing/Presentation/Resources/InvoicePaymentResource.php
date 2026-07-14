<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InvoicePayment',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'amount', type: 'number', format: 'float'),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date'),
        new OA\Property(property: 'method', type: 'string', nullable: true, enum: ['bank_transfer', 'cash', 'card', 'other']),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class InvoicePaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'invoice_id' => $this->resource->invoice_id,
            'amount' => (float) $this->resource->amount,
            'paid_at' => $this->resource->paid_at?->toDateString(),
            'method' => $this->resource->method,
            'note' => $this->resource->note,
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
