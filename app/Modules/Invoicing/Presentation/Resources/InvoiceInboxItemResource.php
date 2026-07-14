<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use App\Modules\Clients\Presentation\Resources\ClientResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InvoiceInboxItem',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'imported', 'ignored', 'failed']),
        new OA\Property(property: 'status_label', type: 'string'),
        new OA\Property(property: 'original_filename', type: 'string'),
        new OA\Property(property: 'mime_type', type: 'string'),
        new OA\Property(property: 'size_bytes', type: 'integer'),
        new OA\Property(property: 'formatted_size', type: 'string', example: '1.2 MB'),
        new OA\Property(property: 'ocr_engine', type: 'string', nullable: true, enum: ['pdfparser', 'tesseract']),
        new OA\Property(property: 'suggestions', type: 'object', nullable: true, description: 'Parsed field suggestions for prefilling the review form'),
        new OA\Property(property: 'matched_client', ref: '#/components/schemas/Client', nullable: true),
        new OA\Property(property: 'supplier_invoice_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'download_url', type: 'string', nullable: true),
        new OA\Property(property: 'error', type: 'string', nullable: true),
        new OA\Property(property: 'scanned_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class InvoiceInboxItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'status' => $this->resource->status,
            'status_label' => $this->resource->statusEnum()->label(),
            'original_filename' => $this->resource->original_filename,
            'mime_type' => $this->resource->mime_type,
            'size_bytes' => $this->resource->size_bytes,
            'formatted_size' => $this->resource->formattedSize(),
            'ocr_engine' => $this->resource->ocr_engine,
            'suggestions' => $this->resource->suggestions,
            'supplier_invoice_id' => $this->resource->supplier_invoice_id,
            'download_url' => route('invoice-inbox.download', $this->resource->id),
            'error' => $this->resource->error,
            'scanned_at' => $this->resource->scanned_at?->toISOString(),
            'created_at' => $this->resource->created_at?->toISOString(),

            'matched_client' => $this->when(
                $this->resource->relationLoaded('matchedClient') && $this->resource->matchedClient !== null,
                fn (): ClientResource => ClientResource::make($this->resource->matchedClient),
            ),
        ];
    }
}
