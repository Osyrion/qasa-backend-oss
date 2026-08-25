<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\PublicInvoiceLookup;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\ValueObjects\PublicInvoice;

final class EloquentPublicInvoiceLookup implements PublicInvoiceLookup
{
    public function byPublicToken(string $publicToken): ?PublicInvoice
    {
        $invoice = Invoice::withoutGlobalScope('user')
            ->where('public_token', $publicToken)
            ->first();

        // A document whose link is off has no public URL, and a token that
        // still matches one is not a link anybody may act on.
        if ($invoice === null || $invoice->publicUrl() === null) {
            return null;
        }

        return $invoice->publicView();
    }

    public function forAccount(string $invoiceId, string $ownerId): ?PublicInvoice
    {
        $invoice = Invoice::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->find($invoiceId);

        return $invoice?->publicView();
    }
}
