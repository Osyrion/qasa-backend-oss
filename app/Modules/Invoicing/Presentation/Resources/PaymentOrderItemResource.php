<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaymentOrderItem',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'supplier_invoice_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'vendor_name', type: 'string'),
        new OA\Property(property: 'supplier_invoice_number', type: 'string'),
        new OA\Property(property: 'account_number', type: 'string', nullable: true),
        new OA\Property(property: 'bank_code', type: 'string', nullable: true),
        new OA\Property(property: 'iban', type: 'string', nullable: true),
        new OA\Property(property: 'bic', type: 'string', nullable: true),
        new OA\Property(property: 'variable_symbol', type: 'string', nullable: true),
        new OA\Property(property: 'amount', type: 'number', format: 'float'),
        new OA\Property(property: 'sort_order', type: 'integer'),
    ]
)]
class PaymentOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'supplier_invoice_id' => $this->resource->supplier_invoice_id,
            'vendor_name' => $this->resource->vendor_name,
            'supplier_invoice_number' => $this->resource->supplier_invoice_number,
            'account_number' => $this->resource->account_number,
            'bank_code' => $this->resource->bank_code,
            'iban' => $this->resource->iban,
            'bic' => $this->resource->bic,
            'variable_symbol' => $this->resource->variable_symbol,
            'amount' => (float) $this->resource->amount,
            'sort_order' => $this->resource->sort_order,
        ];
    }
}
