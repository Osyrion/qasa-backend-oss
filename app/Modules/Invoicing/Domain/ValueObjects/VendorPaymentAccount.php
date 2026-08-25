<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use Illuminate\Support\Carbon;

/**
 * Where a supplier invoice says the money should go, and how much that claim
 * has been checked.
 *
 * The two predicates travel with the values on purpose. "Does this have an
 * account at all" and "is it a domestic pair rather than an IBAN" decide which
 * export format a batch can use and whether a line may be selected at all —
 * getting either subtly different in a second module is how a payment file
 * ends up with a row the bank rejects.
 */
final readonly class VendorPaymentAccount
{
    public function __construct(
        public ?string $accountNumber,
        public ?string $bankCode,
        public ?string $iban,
        public ?string $bic,
        /** How the account got here: manual|ocr. */
        public ?string $source,
        /** When the CZ VAT payer register was last consulted about it. */
        public ?Carbon $verifiedAt,
        /** published|unpublished|unreliable, or null if never checked. */
        public ?string $verificationResult,
    ) {}

    public function exists(): bool
    {
        return $this->isDomestic() || ($this->iban !== null && $this->iban !== '');
    }

    /** A domestic číslo účtu + kód banky pair, which ABO/KPC can carry directly. */
    public function isDomestic(): bool
    {
        return $this->accountNumber !== null && $this->accountNumber !== ''
            && $this->bankCode !== null && $this->bankCode !== '';
    }
}
