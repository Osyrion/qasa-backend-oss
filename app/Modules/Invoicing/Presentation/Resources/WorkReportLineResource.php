<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use App\Modules\Invoicing\Domain\Models\InvoiceWorkReportLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin InvoiceWorkReportLine
 */
#[OA\Schema(
    schema: 'WorkReportLine',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'time_entry_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'work_date', type: 'string', format: 'date'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'hours', type: 'number', format: 'float'),
        new OA\Property(property: 'sort_order', type: 'integer'),
    ]
)]
class WorkReportLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'time_entry_id' => $this->time_entry_id,
            'work_date' => $this->work_date->toDateString(),
            'description' => $this->description,
            'hours' => (float) $this->hours,
            'sort_order' => $this->sort_order,
        ];
    }
}
