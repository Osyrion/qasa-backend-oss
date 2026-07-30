<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupplierInvoiceVatLine',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'vat_rate', type: 'number', format: 'float'),
        new OA\Property(property: 'base', type: 'number', format: 'float'),
        new OA\Property(property: 'vat_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'sort_order', type: 'integer'),
    ]
)]
class SupplierInvoiceVatLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'vat_rate' => (float) $this->resource->vat_rate,
            'base' => (float) $this->resource->base,
            'vat_amount' => (float) $this->resource->vat_amount,
            'sort_order' => $this->resource->sort_order,
        ];
    }
}
