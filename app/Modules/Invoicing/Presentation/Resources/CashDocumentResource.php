<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CashDocument',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string', enum: ['income', 'expense']),
        new OA\Property(property: 'number', type: 'string', example: 'PPD-2026-001'),
        new OA\Property(property: 'issued_at', type: 'string', format: 'date'),
        new OA\Property(property: 'amount', type: 'string', description: 'Always positive; direction comes from type'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'vat_rate', type: 'string', nullable: true),
        new OA\Property(property: 'vat_amount', type: 'string', nullable: true),
        new OA\Property(property: 'counterparty', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'invoice_payment_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'expense_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'documents_existing_record', type: 'boolean', description: 'True when this only papers over an already-recorded payment/expense — excluded from tax income and expenses'),
        new OA\Property(property: 'reverses_cash_document_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'is_reversed', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
class CashDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type->value,
            'number' => $this->resource->number,
            'issued_at' => $this->resource->issued_at->toDateString(),
            'amount' => (string) $this->resource->amount,
            'currency' => $this->resource->currency->value,
            'vat_rate' => $this->resource->vat_rate === null ? null : (string) $this->resource->vat_rate,
            'vat_amount' => $this->resource->vat_amount === null ? null : (string) $this->resource->vat_amount,
            'counterparty' => $this->resource->counterparty,
            'description' => $this->resource->description,
            'note' => $this->resource->note,
            'invoice_payment_id' => $this->resource->invoice_payment_id,
            'expense_id' => $this->resource->expense_id,
            'documents_existing_record' => ! $this->resource->isStandalone(),
            'reverses_cash_document_id' => $this->resource->reverses_cash_document_id,
            'is_reversed' => $this->whenLoaded('reversal', fn (): bool => $this->resource->reversal !== null),
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
