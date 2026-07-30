<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Application\DTOs\VatValidationData;

interface VatValidatorInterface
{
    /**
     * Verify a VAT identification number (IČ DPH) for the given member-state code.
     * Returns null when the validation service is unavailable.
     */
    public function verify(string $countryCode, string $vatNumber): ?VatValidationData;
}
