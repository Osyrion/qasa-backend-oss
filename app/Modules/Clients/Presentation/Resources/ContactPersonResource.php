<?php

declare(strict_types=1);

namespace App\Modules\Clients\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ContactPerson',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string', example: 'Ing.', nullable: true),
        new OA\Property(property: 'name', type: 'string', example: 'Ján'),
        new OA\Property(property: 'surname', type: 'string', example: 'Novák'),
        new OA\Property(property: 'full_name', type: 'string', example: 'Ing. Ján Novák'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'role', type: 'string', example: 'Manager', nullable: true),
        new OA\Property(property: 'is_primary', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class ContactPersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'name' => $this->resource->name,
            'surname' => $this->resource->surname,
            'full_name' => $this->resource->full_name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'role' => $this->resource->role,
            'is_primary' => $this->resource->is_primary,
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
