<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

/**
 * Deterministic conversion of a Czech domestic account number
 * (`[prefix-]number` + 4-digit bank code) to its CZ IBAN — the BBAN layout
 * is fixed (bank code 4 + prefix 6 + number 10), so no lookup is needed.
 */
final class CzechIbanConverter
{
    public function toIban(string $accountNumber, string $bankCode): ?string
    {
        if (preg_match('/^(?:(\d{1,6})-)?(\d{2,10})$/', trim($accountNumber), $m) !== 1
            || preg_match('/^\d{4}$/', trim($bankCode)) !== 1) {
            return null;
        }

        $prefix = str_pad($m[1], 6, '0', STR_PAD_LEFT);
        $number = str_pad($m[2], 10, '0', STR_PAD_LEFT);
        $bban = trim($bankCode).$prefix.$number;

        // ISO 7064 mod 97-10 check digits: mod of BBAN + "CZ00" (C=12, Z=35).
        $checkDigits = str_pad((string) (98 - $this->mod97($bban.'123500')), 2, '0', STR_PAD_LEFT);

        return 'CZ'.$checkDigits.$bban;
    }

    private function mod97(string $digits): int
    {
        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = ((int) ($remainder.$chunk)) % 97;
        }

        return $remainder;
    }
}
