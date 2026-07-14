<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Requests;

use App\Modules\Invoicing\Application\DTOs\InvoiceData;

class UpdateInvoiceRequest extends InvoiceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...InvoiceData::rules(),
            'client_id' => $this->clientIdRules(),
        ];
    }
}
