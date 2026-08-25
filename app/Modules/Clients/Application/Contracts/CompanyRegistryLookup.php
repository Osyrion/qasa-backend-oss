<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Application\DTOs\CompanyRegistryData;

/**
 * Company details from a public register (RPO for SK, ARES for CZ) by IČO.
 *
 * Published because two flows prefill from it — adding a client, and the
 * account's own step-2 residency form, which lives in Taxation.
 */
interface CompanyRegistryLookup
{
    public function execute(string $country, string $ico): CompanyRegistryData;
}
