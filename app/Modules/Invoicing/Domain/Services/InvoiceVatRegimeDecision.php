<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;

final readonly class InvoiceVatRegimeDecision
{
    public function __construct(
        public bool $reverseCharge,
        public ?ReverseChargeMode $mode,
    ) {}
}
