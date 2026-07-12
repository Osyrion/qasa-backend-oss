<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Events;

use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired the first time the invoice's public page is opened. No listener yet
 * — a seam for a future "client opened the invoice" notification.
 */
class InvoiceViewed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
    ) {}
}
