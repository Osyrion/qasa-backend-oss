<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Events;

use App\Modules\Invoicing\Domain\Models\CashDocument;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CashDocumentReversed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CashDocument $original,
        public readonly CashDocument $reversal,
    ) {}
}
