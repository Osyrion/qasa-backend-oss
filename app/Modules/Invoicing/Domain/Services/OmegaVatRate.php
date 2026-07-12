<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

/**
 * Maps a numeric VAT rate to a KROS Omega VAT code via config('omega.vat_codes')
 * — see config/omega.php for the "unverified" caveat on this mapping.
 */
final class OmegaVatRate
{
    public static function codeFor(float $rate): string
    {
        /** @var array<int, string> $codes */
        $codes = config('omega.vat_codes', []);

        return $codes[(int) round($rate)] ?? (string) (int) round($rate);
    }
}
