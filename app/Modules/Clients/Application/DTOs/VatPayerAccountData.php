<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\DTOs;

use Spatie\LaravelData\Data;

/**
 * One bank account published in a VAT payer register — either in the
 * domestic form (number + bank code) or as an IBAN.
 */
class VatPayerAccountData extends Data
{
    public function __construct(
        public readonly ?string $account_number = null,
        public readonly ?string $bank_code = null,
        public readonly ?string $iban = null,
    ) {}
}
