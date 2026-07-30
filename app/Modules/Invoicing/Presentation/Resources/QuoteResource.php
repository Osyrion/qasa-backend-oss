<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use App\Modules\Clients\Presentation\Resources\ClientResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Quote',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quote_number', type: 'string', example: 'CP-2026-001'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'sent', 'accepted', 'rejected', 'expired']),
        new OA\Property(property: 'effective_status', type: 'string', enum: ['draft', 'sent', 'accepted', 'rejected', 'expired']),
        new OA\Property(property: 'issued_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'currency', type: 'string', nullable: true, enum: ['CZK', 'EUR', 'USD']),
        new OA\Property(property: 'discount_percent', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'discount_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'subtotal', type: 'number', format: 'float'),
        new OA\Property(property: 'vat_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'total', type: 'number', format: 'float'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'note_above', type: 'string', nullable: true),
        new OA\Property(property: 'accepted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'rejected_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'decision_note', type: 'string', nullable: true),
        new OA\Property(property: 'emailed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'emailed_to', type: 'string', nullable: true),
        new OA\Property(property: 'converted_invoice_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'converted_order_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(
            property: 'public_link',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'url', type: 'string'),
                new OA\Property(property: 'first_viewed_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'view_count', type: 'integer'),
            ]
        ),
        new OA\Property(property: 'client', ref: '#/components/schemas/Client', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/QuoteItem'), nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class QuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'quote_number' => $this->resource->quote_number,
            'status' => $this->resource->status,
            'effective_status' => $this->resource->effectiveStatus()->value,
            'issued_at' => $this->resource->issued_at?->toDateString(),
            'valid_until' => $this->resource->valid_until?->toDateString(),
            'currency' => $this->resource->currency?->value,
            'discount_percent' => $this->resource->discount_percent !== null
                ? (float) $this->resource->discount_percent
                : null,
            'discount_amount' => (float) $this->resource->discount_amount,
            'subtotal' => (float) $this->resource->subtotal,
            'vat_amount' => (float) $this->resource->vat_amount,
            'total' => (float) $this->resource->total,
            'note' => $this->resource->note,
            'note_above' => $this->resource->note_above,
            'accepted_at' => $this->resource->accepted_at?->toISOString(),
            'rejected_at' => $this->resource->rejected_at?->toISOString(),
            'decision_note' => $this->resource->decision_note,
            'emailed_at' => $this->resource->emailed_at?->toISOString(),
            'emailed_to' => $this->resource->emailed_to,
            'converted_invoice_id' => $this->resource->converted_invoice_id,
            'converted_order_id' => $this->resource->converted_order_id,
            'public_link' => $this->resource->hasPublicLink() ? [
                'url' => $this->resource->publicUrl(),
                'first_viewed_at' => $this->resource->public_first_viewed_at?->toISOString(),
                'view_count' => $this->resource->public_view_count,
            ] : null,

            'client' => $this->when(
                $this->resource->relationLoaded('client'),
                fn (): ClientResource => ClientResource::make($this->resource->client),
            ),

            'items' => $this->when(
                $this->resource->relationLoaded('items'),
                fn () => QuoteItemResource::collection($this->resource->items),
            ),

            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
