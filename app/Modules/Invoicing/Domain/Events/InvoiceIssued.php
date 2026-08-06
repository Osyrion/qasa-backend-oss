<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Events;

use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired on the Draft → Issued transition specifically — the invoice now
 * carries a real number and frozen snapshots but has not necessarily been
 * emailed (Draft → Sent fires InvoiceSent instead, or in addition, if the
 * owner sends directly rather than issuing-then-sending separately).
 */
class InvoiceIssued
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
    ) {}
}
