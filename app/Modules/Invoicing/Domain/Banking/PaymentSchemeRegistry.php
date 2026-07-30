<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Banking;

use App\Modules\Invoicing\Domain\Banking\Contracts\PaymentQrScheme;
use App\Modules\Shared\Enums\Currency;

/**
 * Picks a payment QR scheme from the recipient's own bank account and the
 * document's currency — never tax residency. Priority order (see
 * docs/plans/TAX_RESIDENCY_PHASE_3_BANKING_LAYER.md):
 *
 *   1. SK IBAN            → Pay by Square (any currency)
 *   2. CZ IBAN / domestic → SPAYD (any currency)
 *   3. any other IBAN     → EPC, EUR only
 *   4. otherwise          → no QR
 */
final class PaymentSchemeRegistry
{
    /**
     * @param  list<PaymentQrScheme>  $schemes  priority order
     */
    public function __construct(
        private readonly array $schemes,
    ) {}

    public function schemeFor(BankAccountIdentity $account, Currency $currency): ?PaymentQrScheme
    {
        foreach ($this->schemes as $scheme) {
            if ($scheme->supports($account, $currency)) {
                return $scheme;
            }
        }

        return null;
    }
}
