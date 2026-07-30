<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Events;

use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
    ) {}
}
