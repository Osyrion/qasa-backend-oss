<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

/**
 * @mixin PersonalAccessToken
 */
#[OA\Schema(
    schema: 'Session',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string', example: 'api-token'),
        new OA\Property(property: 'ip_address', type: 'string', nullable: true, example: '203.0.113.7'),
        new OA\Property(property: 'user_agent', type: 'string', nullable: true),
        new OA\Property(property: 'is_current', type: 'boolean'),
        new OA\Property(property: 'has_push_token', type: 'boolean'),
        new OA\Property(property: 'last_used_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class SessionResource extends JsonResource
{
    public function __construct(
        PersonalAccessToken $token,
        private readonly int|string|null $currentTokenId,
    ) {
        parent::__construct($token);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'is_current' => $this->currentTokenId !== null && (string) $this->id === (string) $this->currentTokenId,
            'has_push_token' => $this->push_token !== null,
            'last_used_at' => $this->last_used_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
