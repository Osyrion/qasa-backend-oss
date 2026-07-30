<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use App\Modules\Invoicing\Domain\Models\VatRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin VatRate
 */
#[OA\Schema(
    schema: 'VatRate',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', example: 'SK-23'),
        new OA\Property(property: 'country', type: 'string', example: 'SK'),
        new OA\Property(property: 'rate', type: 'number', format: 'float', example: 23),
        new OA\Property(property: 'label', type: 'string', nullable: true),
        new OA\Property(property: 'is_default', type: 'boolean'),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class VatRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'country' => $this->country,
            'rate' => (float) $this->rate,
            'label' => $this->label,
            'is_default' => $this->is_default,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
