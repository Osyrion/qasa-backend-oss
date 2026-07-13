<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use App\Modules\Clients\Presentation\Resources\ClientResource;
use App\Modules\Invoicing\Domain\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Invoice',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'invoice_number', type: 'string', nullable: true, description: 'Null until the invoice is issued (drafts have no number yet)', example: 'FA-2026-001'),
        new OA\Property(property: 'type', type: 'string', enum: ['invoice', 'proforma', 'credit_note', 'storno']),
        new OA\Property(property: 'related_invoice_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'settled_invoice_id', type: 'string', format: 'uuid', nullable: true, description: 'Set on a proforma once settled into an ordinary invoice'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'sent', 'paid', 'cancelled']),
        new OA\Property(property: 'issued_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'taxable_supply_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'due_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'variable_symbol', type: 'string', nullable: true),
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'discount_percent', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'discount_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'reverse_charge', type: 'boolean'),
        new OA\Property(property: 'reverse_charge_mode', type: 'string', enum: ['domestic', 'eu'], nullable: true),
        new OA\Property(property: 'is_overdue', type: 'boolean'),
        new OA\Property(property: 'days_until_due', type: 'integer'),
        new OA\Property(property: 'currency', type: 'string', nullable: true, enum: ['CZK', 'EUR', 'USD']),
        new OA\Property(property: 'exchange_rate_snapshot', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'subtotal', type: 'number', format: 'float'),
        new OA\Property(property: 'vat_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'total', type: 'number', format: 'float'),
        new OA\Property(property: 'note', type: 'string', nullable: true, description: 'Printed below the items table'),
        new OA\Property(property: 'note_above', type: 'string', nullable: true, description: 'Printed above the items table'),
        new OA\Property(property: 'recurring_template_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'emailed_at', type: 'string', format: 'date-time', nullable: true, description: 'Last time the invoice was queued for email delivery'),
        new OA\Property(property: 'emailed_to', type: 'string', nullable: true, description: 'Primary recipient of the last email'),
        new OA\Property(property: 'emailed_cc', type: 'array', items: new OA\Items(type: 'string'), nullable: true, description: 'CC recipients of the last email'),
        new OA\Property(property: 'email_failed_at', type: 'string', format: 'date-time', nullable: true, description: 'Set when the queued email job permanently failed; cleared on the next send'),
        new OA\Property(property: 'last_reminded_at', type: 'string', format: 'date-time', nullable: true, description: 'Last time a payment reminder was sent'),
        new OA\Property(property: 'reminder_count', type: 'integer'),
        new OA\Property(property: 'balance', type: 'number', format: 'float', description: 'Outstanding amount — total minus recorded payments'),
        new OA\Property(property: 'payment_status', type: 'string', enum: ['unpaid', 'partial', 'paid', 'overpaid']),
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
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/InvoiceItem'), nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'invoice_number' => $this->resource->invoice_number,
            'type' => $this->resource->type?->value,
            'related_invoice_id' => $this->resource->related_invoice_id,
            'settled_invoice_id' => $this->resource->settled_invoice_id,
            'status' => $this->resource->status->value,
            'issued_at' => $this->resource->issued_at?->toDateString(),
            'taxable_supply_at' => $this->resource->taxable_supply_at?->toDateString(),
            'due_at' => $this->resource->due_at?->toDateString(),
            'variable_symbol' => $this->resource->variable_symbol,
            'bank_account_id' => $this->resource->bank_account_id,
            'discount_percent' => $this->resource->discount_percent !== null
                ? (float) $this->resource->discount_percent
                : null,
            'discount_amount' => (float) $this->resource->discount_amount,
            'reverse_charge' => $this->resource->reverse_charge,
            'reverse_charge_mode' => $this->resource->reverse_charge_mode?->value,
            'is_overdue' => $this->resource->isOverdue(),
            'days_until_due' => $this->resource->daysUntilDue(),
            'currency' => $this->resource->currency?->value,
            'exchange_rate_snapshot' => $this->resource->exchange_rate_snapshot !== null
                ? (float) $this->resource->exchange_rate_snapshot
                : null,
            'subtotal' => (float) $this->resource->subtotal,
            'vat_amount' => (float) $this->resource->vat_amount,
            'total' => (float) $this->resource->total,
            'note' => $this->resource->note,
            'note_above' => $this->resource->note_above,
            'recurring_template_id' => $this->resource->recurring_template_id,
            'emailed_at' => $this->resource->emailed_at?->toISOString(),
            'emailed_to' => $this->resource->emailed_to,
            'emailed_cc' => $this->resource->emailed_cc,
            'email_failed_at' => $this->resource->email_failed_at?->toISOString(),
            'last_reminded_at' => $this->resource->last_reminded_at?->toISOString(),
            'reminder_count' => $this->resource->reminder_count,
            'balance' => $this->resource->balance(),
            'payment_status' => PaymentStatus::fromAmounts(
                (float) $this->resource->total,
                (float) $this->resource->total - $this->resource->balance(),
            )->value,
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
                fn () => InvoiceItemResource::collection($this->resource->items),
            ),

            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
