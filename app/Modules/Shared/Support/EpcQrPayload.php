<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Support\Str;

/**
 * SEPA credit transfer QR payload per EPC069-12 (version 002, UTF-8).
 * EUR only; BIC is optional in v002.
 *
 * Here rather than in Invoicing because it is a **format**, not a fact about a
 * document: the operator's own subscription orders are paid with one of these
 * too, and a module asking Invoicing to encode a bank transfer was asking the
 * wrong module. Invoicing keeps the scheme adapter that picks between this,
 * Pay by Square and SPAYD — choosing is its business, encoding is not.
 */
final class EpcQrPayload
{
    public function build(
        string $iban,
        ?string $bic,
        string $beneficiaryName,
        float $amount,
        ?string $remittanceText = null,
    ): string {
        $lines = [
            'BCD',
            '002',
            '1',
            'SCT',
            $bic !== null ? strtoupper($bic) : '',
            Str::limit(trim($beneficiaryName), 70, ''),
            strtoupper(str_replace(' ', '', $iban)),
            'EUR'.number_format($amount, 2, '.', ''),
            '', // purpose code
            '', // structured remittance reference
            Str::limit(trim((string) $remittanceText), 140, ''),
        ];

        return implode("\n", $lines);
    }
}
