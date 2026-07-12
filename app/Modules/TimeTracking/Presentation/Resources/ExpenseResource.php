<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'description' => $this->resource->description,
            'category' => $this->resource->category,
            'amount' => (float) $this->resource->amount,
            'currency' => $this->resource->currency?->value,
            'date' => $this->resource->date?->toDateString(),
            'note' => $this->resource->note,
            'attachment' => $this->resource->hasAttachment() ? [
                'filename' => $this->resource->attachment_filename,
                'mime_type' => $this->resource->attachment_mime_type,
                'size_bytes' => $this->resource->attachment_size_bytes,
            ] : null,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
