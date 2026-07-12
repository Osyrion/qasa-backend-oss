<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Presentation\Resources;

use App\Modules\Integrations\Domain\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin WebhookDelivery
 */
#[OA\Schema(
    schema: 'WebhookDelivery',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'event', type: 'string', example: 'invoice.paid'),
        new OA\Property(property: 'attempt', type: 'integer'),
        new OA\Property(property: 'response_status', type: 'integer', nullable: true),
        new OA\Property(property: 'response_excerpt', type: 'string', nullable: true),
        new OA\Property(property: 'delivered_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'failed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'attempt' => $this->attempt,
            'response_status' => $this->response_status,
            'response_excerpt' => $this->response_excerpt,
            'delivered_at' => $this->delivered_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
