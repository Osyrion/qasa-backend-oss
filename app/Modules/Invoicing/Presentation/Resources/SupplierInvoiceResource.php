<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use App\Modules\Clients\Presentation\Resources\ClientResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupplierInvoice',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'internal_number', type: 'string', example: 'DF-2026-001'),
        new OA\Property(property: 'supplier_invoice_number', type: 'string'),
        new OA\Property(property: 'variable_symbol', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'received', 'booked', 'paid', 'cancelled']),
        new OA\Property(property: 'status_label', type: 'string'),
        new OA\Property(property: 'vat_regime', type: 'string', enum: ['domestic', 'eu_reverse_charge', 'import']),
        new OA\Property(property: 'issued_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'taxable_supply_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'due_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'received_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'currency', type: 'string', nullable: true, enum: ['CZK', 'EUR', 'USD']),
        new OA\Property(property: 'exchange_rate', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'subtotal', type: 'number', format: 'float'),
        new OA\Property(property: 'vat_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'total', type: 'number', format: 'float'),
        new OA\Property(property: 'self_assessed_vat_amount', type: 'number', format: 'float', description: 'Mirrors vat_amount for self-assessed regimes; not owed to the vendor'),
        new OA\Property(property: 'vendor_snapshot', type: 'object', nullable: true, description: 'Frozen at received'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'client', ref: '#/components/schemas/Client', nullable: true),
        new OA\Property(property: 'vat_lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/SupplierInvoiceVatLine'), nullable: true),
        new OA\Property(property: 'has_attachment', type: 'boolean', nullable: true),
        new OA\Property(property: 'inbox_download_url', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class SupplierInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'internal_number' => $this->resource->internal_number,
            'supplier_invoice_number' => $this->resource->supplier_invoice_number,
            'variable_symbol' => $this->resource->variable_symbol,
            'status' => $this->resource->status,
            'status_label' => $this->resource->statusEnum()->label(),
            'vat_regime' => $this->resource->vat_regime->value,
            'issued_at' => $this->resource->issued_at?->toDateString(),
            'taxable_supply_at' => $this->resource->taxable_supply_at?->toDateString(),
            'due_at' => $this->resource->due_at?->toDateString(),
            'received_at' => $this->resource->received_at?->toDateString(),
            'paid_at' => $this->resource->paid_at?->toDateString(),
            'currency' => $this->resource->currency?->value,
            'exchange_rate' => $this->resource->exchange_rate !== null
                ? (float) $this->resource->exchange_rate
                : null,
            'subtotal' => (float) $this->resource->subtotal,
            'vat_amount' => (float) $this->resource->vat_amount,
            'total' => (float) $this->resource->total,
            'self_assessed_vat_amount' => (float) $this->resource->self_assessed_vat_amount,
            'vendor_snapshot' => $this->resource->vendor_snapshot,
            'note' => $this->resource->note,

            'client' => $this->when(
                $this->resource->relationLoaded('client'),
                fn (): ClientResource => ClientResource::make($this->resource->client),
            ),

            'vat_lines' => $this->when(
                $this->resource->relationLoaded('vatLines'),
                fn () => SupplierInvoiceVatLineResource::collection($this->resource->vatLines),
            ),

            'has_attachment' => $this->when(
                $this->resource->relationLoaded('inboxItem'),
                fn (): bool => $this->resource->inboxItem !== null,
            ),

            'inbox_download_url' => $this->when(
                $this->resource->relationLoaded('inboxItem') && $this->resource->inboxItem !== null,
                fn (): string => route('invoice-inbox.download', $this->resource->inboxItem->id),
            ),

            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
