<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TaxFiling',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string', enum: ['control_statement', 'eu_sales_list', 'income_tax', 'vat_return']),
        new OA\Property(property: 'country', type: 'string', enum: ['SK', 'CZ']),
        new OA\Property(property: 'period_year', type: 'integer', example: 2026),
        new OA\Property(property: 'period_quarter', type: 'integer', nullable: true, example: 3),
        new OA\Property(property: 'period_month', type: 'integer', nullable: true, example: 7),
        new OA\Property(property: 'content', type: 'string', description: 'Raw generated document — XML for control_statement, JSON for eu_sales_list'),
        new OA\Property(property: 'sha256', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['generated', 'filed', 'superseded']),
        new OA\Property(property: 'filed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'supersedes_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class TaxFilingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type->value,
            'country' => $this->resource->country,
            'period_year' => $this->resource->period_year,
            'period_quarter' => $this->resource->period_quarter,
            'period_month' => $this->resource->period_month,
            'content' => $this->resource->content,
            'sha256' => $this->resource->sha256,
            'status' => $this->resource->status->value,
            'filed_at' => $this->resource->filed_at?->toISOString(),
            'notes' => $this->resource->notes,
            'supersedes_id' => $this->resource->supersedes_id,
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
