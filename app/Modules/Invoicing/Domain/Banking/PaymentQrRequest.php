<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Banking;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

/**
 * Superset of fields any PaymentQrScheme might need — each scheme's
 * payload() picks what it uses, so PaymentQrService/SupplierPaymentQrService
 * can dispatch without knowing which concrete scheme was selected.
 */
final readonly class PaymentQrRequest
{
    public function __construct(
        public string $iban,
        public ?string $bic,
        public float $amount,
        public Currency $currency,
        public ?string $variableSymbol = null,
        public ?string $beneficiaryName = null,
        public ?string $message = null,
        public ?Carbon $dueDate = null,
    ) {}
}
