<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

/**
 * The bank account a document was issued to be paid into, as it froze it —
 * `bank_account_snapshot`.
 *
 * Third of the three frozen JSON columns, and the smallest. Same reasoning as
 * {@see DocumentParty}: a payment instruction printed on paper must keep
 * saying where the money was to go, so the columns cannot be a live relation —
 * but that does not mean every reader should know their keys.
 *
 * Not {@see BankAccountIdentity}: that one normalises an IBAN or a domestic
 * number *in order to pick a QR scheme*, and deliberately throws away
 * everything a person reads.
 */
final readonly class DocumentBankAccount
{
    public function __construct(
        public ?string $label,
        public ?string $bankName,
        public ?string $accountNumber,
        public ?string $iban,
        public ?string $bic,
        /** The account's own currency, as recorded. Not necessarily the document's. */
        public ?string $currency,
    ) {}

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function fromSnapshot(?array $snapshot): ?self
    {
        if ($snapshot === null || $snapshot === []) {
            return null;
        }

        return new self(
            label: self::text($snapshot, 'label'),
            bankName: self::text($snapshot, 'bank_name'),
            accountNumber: self::text($snapshot, 'account_number'),
            iban: self::text($snapshot, 'iban'),
            bic: self::text($snapshot, 'bic'),
            currency: self::text($snapshot, 'currency'),
        );
    }

    /**
     * The same six keys, written back out — `payment_orders.payer_snapshot`
     * freezes exactly what `invoices.bank_account_snapshot` does, and one
     * shape read two ways is how the two start disagreeing.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'label' => $this->label,
            'bank_name' => $this->bankName,
            'account_number' => $this->accountNumber,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'currency' => $this->currency,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function text(array $snapshot, string $key): ?string
    {
        $value = $snapshot[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
