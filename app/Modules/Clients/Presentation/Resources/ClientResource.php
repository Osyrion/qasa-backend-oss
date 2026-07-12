<?php

declare(strict_types=1);

namespace App\Modules\Clients\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Client',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'client_type', type: 'string', enum: ['individual', 'self_employed', 'company']),
        new OA\Property(property: 'display_name', type: 'string', example: 'Ján Novák'),
        new OA\Property(property: 'title', type: 'string', example: 'Ing.', nullable: true),
        new OA\Property(property: 'name', type: 'string', example: 'Ján', nullable: true),
        new OA\Property(property: 'surname', type: 'string', example: 'Novák', nullable: true),
        new OA\Property(property: 'company_name', type: 'string', example: 'ACME s.r.o.', nullable: true),
        new OA\Property(property: 'avatar_path', type: 'string', nullable: true),
        new OA\Property(property: 'color', type: 'string', example: '#3B82F6', nullable: true),
        new OA\Property(property: 'ico', type: 'string', example: '12345678', nullable: true),
        new OA\Property(property: 'dic', type: 'string', example: '1234567890', nullable: true),
        new OA\Property(property: 'vat_id', type: 'string', example: 'SK1234567890', nullable: true),
        new OA\Property(property: 'is_vat_payer', type: 'boolean', nullable: true),
        new OA\Property(property: 'reverse_charge_allowed', type: 'boolean'),
        new OA\Property(property: 'vat_verified_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_customer', type: 'boolean'),
        new OA\Property(property: 'is_vendor', type: 'boolean'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'address', type: 'string', nullable: true),
        new OA\Property(property: 'city', type: 'string', nullable: true),
        new OA\Property(property: 'postal_code', type: 'string', nullable: true),
        new OA\Property(property: 'country', type: 'string', example: 'SK', nullable: true),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR', nullable: true),
        new OA\Property(property: 'locale', type: 'string', example: 'sk', nullable: true),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'orders_count', type: 'integer', nullable: true),
        new OA\Property(property: 'invoices_count', type: 'integer', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'client_type' => $this->resource->client_type,
            'display_name' => $this->resource->display_name,
            'title' => $this->resource->title,
            'name' => $this->resource->name,
            'surname' => $this->resource->surname,
            'company_name' => $this->resource->company_name,
            'avatar_path' => $this->resource->avatar_path,
            'color' => $this->resource->color,
            'ico' => $this->resource->ico,
            'dic' => $this->resource->dic,
            'vat_id' => $this->resource->vat_id,
            'is_vat_payer' => $this->resource->is_vat_payer,
            'reverse_charge_allowed' => $this->resource->reverse_charge_allowed,
            'vat_verified_at' => $this->resource->vat_verified_at?->toISOString(),
            'is_customer' => $this->resource->is_customer,
            'is_vendor' => $this->resource->is_vendor,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'address' => $this->resource->address,
            'city' => $this->resource->city,
            'postal_code' => $this->resource->postal_code,
            'country' => $this->resource->country,
            'currency' => $this->resource->currency?->value,
            'locale' => $this->resource->locale,
            'note' => $this->resource->note,

            'contact_persons' => $this->when(
                $this->resource->relationLoaded('contactPersons'),
                fn (): AnonymousResourceCollection => ContactPersonResource::collection($this->resource->contactPersons),
            ),

            'orders_count' => $this->when(
                isset($this->orders_count),
                fn (): int => (int) $this->resource->orders_count,
            ),

            'invoices_count' => $this->when(
                isset($this->invoices_count),
                fn (): int => (int) $this->resource->invoices_count,
            ),

            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
