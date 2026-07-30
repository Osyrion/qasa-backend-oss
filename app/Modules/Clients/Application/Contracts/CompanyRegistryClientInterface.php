<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Application\DTOs\CompanyRegistryData;

interface CompanyRegistryClientInterface
{
    /**
     * Fetch company data by its registration number (IČO).
     * Returns null when the company is not found or the register is unreachable.
     */
    public function fetchByIco(string $ico): ?CompanyRegistryData;
}
