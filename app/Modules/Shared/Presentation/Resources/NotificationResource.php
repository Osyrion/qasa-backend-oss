<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * `type` is deliberately absent: the stored value is the notification class
 * name, and the front end renders from `category`/`severity`/`action_url`
 * instead — which is what keeps a class rename from being a breaking API
 * change.
 */
#[OA\Schema(
    schema: 'Notification',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'category', type: 'string', enum: ['invoice', 'quote', 'tax', 'billing', 'banking', 'system']),
        new OA\Property(property: 'severity', type: 'string', enum: ['info', 'success', 'warning', 'error']),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'body', type: 'string'),
        new OA\Property(property: 'action_url', type: 'string', nullable: true),
        new OA\Property(property: 'subject_type', type: 'string', nullable: true),
        new OA\Property(property: 'subject_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource->data;

        return [
            'id' => $this->resource->id,
            'category' => $data['category'] ?? 'system',
            'severity' => $data['severity'] ?? 'info',
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'action_url' => $data['action_url'] ?? null,
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'read_at' => $this->resource->read_at?->toISOString(),
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
