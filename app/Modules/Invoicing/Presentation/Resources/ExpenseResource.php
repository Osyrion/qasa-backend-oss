<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Expense',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'category', type: 'string', enum: ['office', 'travel', 'software', 'hardware', 'marketing', 'education', 'services', 'other']),
        new OA\Property(property: 'amount', type: 'number', format: 'float'),
        new OA\Property(property: 'currency', type: 'string', nullable: true, enum: ['CZK', 'EUR', 'USD']),
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(
            property: 'attachment',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'filename', type: 'string', nullable: true),
                new OA\Property(property: 'mime_type', type: 'string', nullable: true),
                new OA\Property(property: 'size_bytes', type: 'integer', nullable: true),
            ]
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
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
