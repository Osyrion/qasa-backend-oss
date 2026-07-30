<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Models\Invoice;

readonly class RevokeInvoicePublicLinkAction
{
    public function execute(Invoice $invoice): Invoice
    {
        $invoice->forceFill(['public_token' => null])->save();

        return $invoice;
    }
}
