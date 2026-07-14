<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Resources;

use App\Modules\Shared\Domain\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin ActivityLog
 */
#[OA\Schema(
    schema: 'ActivityLog',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'actor_id', type: 'string', format: 'uuid', nullable: true, description: 'Null for system-triggered events'),
        new OA\Property(property: 'subject_type', type: 'string', example: 'invoice'),
        new OA\Property(property: 'subject_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'event', type: 'string', example: 'invoice.status_changed'),
        new OA\Property(property: 'changes', type: 'object', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actor_id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'event' => $this->event,
            'changes' => $this->changes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
