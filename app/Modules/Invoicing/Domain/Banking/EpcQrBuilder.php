<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Banking;

use App\Modules\Invoicing\Domain\Banking\Contracts\PaymentQrScheme;
use App\Modules\Invoicing\Domain\ValueObjects\BankAccountIdentity;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Support\EpcQrPayload;

/**
 * The EPC069-12 scheme as this module's registry sees it.
 *
 * Catch-all: any IBAN in EUR that isn't SK (Pay by Square) or CZ (SPAYD) —
 * see PaymentSchemeRegistry for the priority order. The payload itself is
 * {@see EpcQrPayload}, in Shared: deciding *which* scheme a document's account
 * takes is ours, encoding a SEPA credit transfer is not, and the operator's
 * own subscription orders need the encoding without any of this.
 */
final class EpcQrBuilder implements PaymentQrScheme
{
    public function __construct(
        private readonly EpcQrPayload $payload = new EpcQrPayload,
    ) {}

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
        return $this->payload->build(
            iban: $request->iban,
            bic: $request->bic,
            beneficiaryName: $request->beneficiaryName ?? '',
            amount: $request->amount,
            remittanceText: $request->message,
        );
    }
}
