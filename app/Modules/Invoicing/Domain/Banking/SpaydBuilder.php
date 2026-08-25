<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Banking;

use App\Modules\Invoicing\Domain\Banking\Contracts\PaymentQrScheme;
use App\Modules\Invoicing\Domain\ValueObjects\BankAccountIdentity;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Short Payment Descriptor (SPAYD) payload for Czech QR platba.
 * Spec: https://qr-platba.cz/pro-vyvojare/specifikace-formatu/
 *
 * Selected for any CZ account (IBAN or domestic) regardless of currency —
 * the CC: field carries whatever currency the document is in.
 */
final class SpaydBuilder implements PaymentQrScheme
{
    public function name(): string
    {
        return 'spayd';
    }

    public function supports(BankAccountIdentity $account, Currency $currency): bool
    {
        return $account->isCz();
    }

    public function payload(PaymentQrRequest $request): string
    {
        return $this->build(
            iban: $request->iban,
            bic: $request->bic,
            amount: $request->amount,
            currency: $request->currency,
            variableSymbol: $request->variableSymbol,
            message: $request->message,
            dueDate: $request->dueDate,
        );
    }

    public function build(
        string $iban,
        ?string $bic,
        float $amount,
        Currency $currency,
        ?string $variableSymbol = null,
        ?string $message = null,
        ?Carbon $dueDate = null,
    ): string {
        $account = strtoupper(str_replace(' ', '', $iban));

        if ($bic !== null && $bic !== '') {
            $account .= '+'.strtoupper($bic);
        }

        $parts = [
            'SPD*1.0',
            'ACC:'.$account,
            'AM:'.number_format($amount, 2, '.', ''),
            'CC:'.$currency->value,
        ];

        if ($variableSymbol !== null && $variableSymbol !== '') {
            $parts[] = 'X-VS:'.preg_replace('/\D/', '', $variableSymbol);
        }

        if ($message !== null && $message !== '') {
            $parts[] = 'MSG:'.$this->sanitize($message);
        }

        if ($dueDate !== null) {
            $parts[] = 'DT:'.$dueDate->format('Ymd');
        }

        return implode('*', $parts);
    }

    /**
     * SPAYD values are ASCII; `*` is the field separator and must not appear.
     */
    private function sanitize(string $value): string
    {
        $ascii = Str::ascii($value);

        return Str::limit(str_replace('*', '', $ascii), 60, '');
    }
}
