<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Banking\Contracts;

use App\Modules\Invoicing\Domain\Banking\BankAccountIdentity;
use App\Modules\Invoicing\Domain\Banking\PaymentQrRequest;
use App\Modules\Shared\Enums\Currency;

/**
 * A payment QR format (Pay by Square, SPAYD, EPC) — selected by
 * PaymentSchemeRegistry from the recipient's own bank account and the
 * document's currency, never from tax residency.
 */
interface PaymentQrScheme
{
    /**
     * Stable identifier returned to API consumers, e.g. invoice
     * Resource's qr_scheme field ('paybysquare'|'spayd'|'epc').
     */
    public function name(): string;

    public function supports(BankAccountIdentity $account, Currency $currency): bool;

    public function payload(PaymentQrRequest $request): string;
}
