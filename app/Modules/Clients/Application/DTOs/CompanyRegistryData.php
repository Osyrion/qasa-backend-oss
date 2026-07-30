<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\DTOs;

use Spatie\LaravelData\Data;

/**
 * Company data fetched from a public business register (ARES / RPO) to
 * prefill the client create form. Never persisted directly.
 */
class CompanyRegistryData extends Data
{
    public function __construct(
        public readonly ?string $company_name,
        public readonly ?string $ico,
        public readonly ?string $dic,
        public readonly ?string $vat_id,
        public readonly ?string $address,
        public readonly ?string $city,
        public readonly ?string $postal_code,
        public readonly string $country,
    ) {}
}
