<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Presentation\Resources;

use App\Modules\Integrations\Domain\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin WebhookEndpoint
 */
#[OA\Schema(
    schema: 'WebhookEndpoint',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'url', type: 'string', example: 'https://example.com/webhooks/qasa'),
        new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'string', example: 'invoice.paid')),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'consecutive_failures', type: 'integer'),
        new OA\Property(property: 'disabled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_success_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_failure_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class WebhookEndpointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'events' => $this->events,
            'is_active' => $this->is_active,
            'consecutive_failures' => $this->consecutive_failures,
            'disabled_at' => $this->disabled_at?->toISOString(),
            'last_success_at' => $this->last_success_at?->toISOString(),
            'last_failure_at' => $this->last_failure_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
