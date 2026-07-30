<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Banking;

/**
 * Normalized bank account identity used only to pick a QR scheme / payment
 * order format — either an IBAN or a CZ domestic account number + bank
 * code, whichever the caller has on hand. Never converts between the two
 * (that's CzechIbanConverter, used where a payload actually needs an IBAN);
 * this is purely for country/format classification.
 */
final readonly class BankAccountIdentity
{
    private function __construct(
        public ?string $iban,
        public ?string $domesticAccountNumber,
        public ?string $domesticBankCode,
    ) {}

    public static function fromIban(string $iban): self
    {
        return new self(strtoupper(str_replace(' ', '', $iban)), null, null);
    }

    public static function fromDomestic(string $accountNumber, string $bankCode): self
    {
        return new self(null, $accountNumber, $bankCode);
    }

    /**
     * Prefers an explicit IBAN; falls back to a CZ domestic account number +
     * bank code. Null when neither is present.
     */
    public static function resolve(?string $iban, ?string $accountNumber, ?string $bankCode): ?self
    {
        if ($iban !== null && $iban !== '') {
            return self::fromIban($iban);
        }

        if ($accountNumber !== null && $accountNumber !== '' && $bankCode !== null && $bankCode !== '') {
            return self::fromDomestic($accountNumber, $bankCode);
        }

        return null;
    }

    public function hasIban(): bool
    {
        return $this->iban !== null;
    }

    public function isSkIban(): bool
    {
        return $this->iban !== null && str_starts_with($this->iban, 'SK');
    }

    /**
     * A CZ account — either a CZ IBAN or a domestic (non-IBAN) account
     * number + bank code pair, which is always CZ by construction.
     */
    public function isCz(): bool
    {
        return $this->domesticAccountNumber !== null
            || ($this->iban !== null && str_starts_with($this->iban, 'CZ'));
    }
}
