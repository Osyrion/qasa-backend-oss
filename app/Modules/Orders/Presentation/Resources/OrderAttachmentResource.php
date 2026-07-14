<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderAttachment',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'filename', type: 'string'),
        new OA\Property(property: 'display_name', type: 'string'),
        new OA\Property(property: 'label', type: 'string', nullable: true),
        new OA\Property(property: 'mime_type', type: 'string'),
        new OA\Property(property: 'size_bytes', type: 'integer'),
        new OA\Property(property: 'size_human', type: 'string'),
        new OA\Property(property: 'disk', type: 'string'),
        new OA\Property(property: 'url', type: 'string', format: 'uri'),
        new OA\Property(property: 'is_external', type: 'boolean'),
        new OA\Property(property: 'is_image', type: 'boolean'),
        new OA\Property(property: 'is_pdf', type: 'boolean'),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class OrderAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'filename' => $this->resource->filename,
            'display_name' => $this->resource->display_name,
            'label' => $this->resource->label,
            'mime_type' => $this->resource->mime_type,
            'size_bytes' => $this->resource->size_bytes,
            'size_human' => $this->resource->formattedSize(),
            'disk' => $this->resource->disk,
            'url' => $this->resource->url,
            'is_external' => $this->resource->isExternal(),
            'is_image' => $this->resource->isImage(),
            'is_pdf' => $this->resource->isPdf(),
            'sort_order' => $this->resource->sort_order,
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
