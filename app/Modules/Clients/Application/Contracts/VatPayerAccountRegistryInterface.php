<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Application\DTOs\VatPayerAccountRegistryData;

interface VatPayerAccountRegistryInterface
{
    /**
     * Published bank accounts + reliability status of a VAT payer.
     * Null when the register is unreachable.
     */
    public function lookup(string $dic): ?VatPayerAccountRegistryData;
}
