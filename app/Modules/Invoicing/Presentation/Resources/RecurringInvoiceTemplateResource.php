<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use App\Modules\Clients\Presentation\Resources\ClientResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RecurringInvoiceTemplate',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'paused', 'expired']),
        new OA\Property(property: 'period', type: 'string', enum: ['monthly', 'quarterly', 'semiannually', 'yearly']),
        new OA\Property(property: 'day_of_month', type: 'integer', minimum: 1, maximum: 28),
        new OA\Property(property: 'last_day_of_month', type: 'boolean'),
        new OA\Property(property: 'first_issue_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'next_run_date', type: 'string', format: 'date'),
        new OA\Property(property: 'last_generated_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'type', type: 'string', enum: ['invoice', 'proforma']),
        new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
        new OA\Property(property: 'due_days', type: 'integer'),
        new OA\Property(property: 'discount_percent', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'reverse_charge', type: 'boolean', description: 'Intent only — re-resolved from the current client at each generation'),
        new OA\Property(property: 'tax_date_mode', type: 'string', enum: ['issue_date', 'previous_month_end']),
        new OA\Property(property: 'auto_send', type: 'boolean', description: 'Issue and email generated invoices automatically'),
        new OA\Property(property: 'note_above', type: 'string', nullable: true, description: 'Supports period placeholders {BOM}, {EOM}, {MONTH}, {YEAR}'),
        new OA\Property(property: 'note_below', type: 'string', nullable: true, description: 'Supports period placeholders {BOM}, {EOM}, {MONTH}, {YEAR}'),
        new OA\Property(property: 'client', ref: '#/components/schemas/Client', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/RecurringTemplateItem'), nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class RecurringInvoiceTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'client_id' => $this->resource->client_id,
            'status' => $this->resource->status->value,
            'period' => $this->resource->period->value,
            'day_of_month' => $this->resource->day_of_month,
            'last_day_of_month' => $this->resource->last_day_of_month,
            'first_issue_date' => $this->resource->first_issue_date->toDateString(),
            'end_date' => $this->resource->end_date?->toDateString(),
            'next_run_date' => $this->resource->next_run_date->toDateString(),
            'last_generated_at' => $this->resource->last_generated_at?->toDateString(),
            'type' => $this->resource->type->value,
            'currency' => $this->resource->currency->value,
            'due_days' => $this->resource->due_days,
            'discount_percent' => $this->resource->discount_percent !== null
                ? (float) $this->resource->discount_percent
                : null,
            'reverse_charge' => $this->resource->reverse_charge,
            'tax_date_mode' => $this->resource->tax_date_mode->value,
            'auto_send' => $this->resource->auto_send,
            'note_above' => $this->resource->note_above,
            'note_below' => $this->resource->note_below,

            'client' => $this->when(
                $this->resource->relationLoaded('client'),
                fn (): ClientResource => ClientResource::make($this->resource->client),
            ),

            'items' => $this->when(
                $this->resource->relationLoaded('items'),
                fn () => RecurringTemplateItemResource::collection($this->resource->items),
            ),

            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
