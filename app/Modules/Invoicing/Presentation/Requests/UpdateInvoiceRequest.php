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
            // Optimistic locking: the client echoes back the updated_at it
            // last saw; a mismatch means someone else changed the invoice
            // since, and the update is rejected rather than silently
            // overwriting their change.
            'expected_updated_at' => ['nullable', 'date'],
        ];
    }
}
