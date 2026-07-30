<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ContributionPayment',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string', enum: ['social', 'health', 'income_tax_advance']),
        new OA\Property(property: 'period_year', type: 'integer', example: 2026),
        new OA\Property(property: 'period_month', type: 'integer', nullable: true, example: 3),
        new OA\Property(property: 'amount', type: 'number', format: 'float'),
        new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class ContributionPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type->value,
            'period_year' => $this->resource->period_year,
            'period_month' => $this->resource->period_month,
            'amount' => (float) $this->resource->amount,
            'currency' => $this->resource->currency->value,
            'paid_at' => $this->resource->paid_at?->toDateString(),
            'note' => $this->resource->note,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
