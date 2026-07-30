<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\DTOs;

use Spatie\LaravelData\Data;

/**
 * Result of a VAT identification number (IČ DPH) check against VIES.
 */
class VatValidationData extends Data
{
    public function __construct(
        public readonly bool $valid,
        public readonly string $country,
        public readonly string $vat_number,
        public readonly ?string $name,
        public readonly ?string $address,
    ) {}
}
