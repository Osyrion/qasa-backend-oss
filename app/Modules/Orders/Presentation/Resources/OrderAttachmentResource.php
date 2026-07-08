<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderAttachmentResource extends JsonResource
{
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
