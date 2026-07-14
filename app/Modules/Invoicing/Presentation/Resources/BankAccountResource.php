<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Resources;

use App\Modules\Invoicing\Domain\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin BankAccount
 */
#[OA\Schema(
    schema: 'BankAccount',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'bank_name', type: 'string', nullable: true),
        new OA\Property(property: 'account_number', type: 'string', nullable: true, example: '123456789/0100'),
        new OA\Property(property: 'iban', type: 'string', nullable: true),
        new OA\Property(property: 'bic', type: 'string', nullable: true),
        new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
        new OA\Property(property: 'is_default', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class BankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'currency' => $this->currency->value,
            'is_default' => $this->is_default,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
