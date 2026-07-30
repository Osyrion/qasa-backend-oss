<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Banking;

use App\Modules\Invoicing\Domain\Banking\Contracts\PaymentQrScheme;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Str;

/**
 * SEPA credit transfer QR payload per EPC069-12 (version 002, UTF-8).
 * EUR only; BIC is optional in v002.
 *
 * Catch-all: any IBAN in EUR that isn't SK (Pay by Square) or CZ (SPAYD) —
 * see PaymentSchemeRegistry for the priority order.
 */
final class EpcQrBuilder implements PaymentQrScheme
{
    public function name(): string
    {
        return 'epc';
    }

    public function supports(BankAccountIdentity $account, Currency $currency): bool
    {
        return $account->hasIban() && $currency === Currency::EUR;
    }

    public function payload(PaymentQrRequest $request): string
    {
        return $this->build(
            iban: $request->iban,
            bic: $request->bic,
            beneficiaryName: $request->beneficiaryName ?? '',
            amount: $request->amount,
            remittanceText: $request->message,
        );
    }

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
