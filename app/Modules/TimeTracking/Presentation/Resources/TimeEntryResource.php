<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimeEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'order_id' => $this->resource->order_id,
            'order_item_id' => $this->resource->order_item_id,
            'description' => $this->resource->description,
            'started_at' => $this->resource->started_at?->toISOString(),
            'ended_at' => $this->resource->ended_at?->toISOString(),
            'is_running' => $this->resource->isRunning(),
            'duration_seconds' => $this->resource->effectiveDurationSeconds(),
            'duration_formatted' => $this->resource->formattedDuration(),
            'rate_override' => $this->resource->rate_override !== null ? (float) $this->resource->rate_override : null,
            'vat_rate' => (float) $this->resource->vat_rate,
            'is_billable' => $this->resource->is_billable,
            'is_invoiced' => $this->resource->is_invoiced,
            'source' => $this->resource->source,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
