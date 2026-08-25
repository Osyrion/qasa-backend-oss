<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Domain\ValueObjects\PartyProfile;

/**
 * The counterparty as a document froze it — `client_snapshot` on an invoice or
 * a quote, `vendor_snapshot` on a supplier invoice.
 *
 * Those columns are JSON, and until now every reader knew their key layout by
 * heart: an accounting export, a control statement, a payment order and four
 * PDF templates each spelling out `$snapshot['is_vat_payer']` for itself.
 * That is a database schema published as string literals — worse than
 * publishing the model, because a typo reads as null instead of failing.
 *
 * Deliberately not {@see PartyProfile}. That value is a *live* client, and it
 * carries two fields a document never freezes — the currency to invoice in and
 * whether reverse charge was agreed — while requiring a country a snapshot
 * written years ago may simply not have. Reading old paper into it would mean
 * inventing the missing halves, which is the one thing a frozen document must
 * never do. It carries a Peppol participant id instead, which PartyProfile has
 * no business holding: that is an address for a network, not an identity on a
 * page.
 *
 * Every field is tolerant on the way in, because the earliest documents in the
 * database were written by code that recorded fewer of them.
 */
final readonly class DocumentParty
{
    public function __construct(
        public string $name,
        public ?string $ico,
        public ?string $dic,
        /** IČ DPH / VAT ID — distinct from $dic, which is the income-tax number. */
        public ?string $vatId,
        public bool $isVatPayer,
        public ?string $address,
        public ?string $city,
        public ?string $postalCode,
        /** ISO 3166-1 alpha-2. Nullable here, unlike on a live client: old snapshots predate the column being required. */
        public ?string $country,
        public ?string $email,
        public ?string $phone,
        /** Peppol participant id (scheme:identifier), frozen so the paper keeps saying where it was addressed. */
        public ?string $peppolId,
    ) {}

    public static function fromProfile(PartyProfile $profile, ?string $peppolId = null): self
    {
        return new self(
            name: $profile->name,
            ico: $profile->ico,
            dic: $profile->dic,
            vatId: $profile->vatId,
            isVatPayer: $profile->isVatPayer,
            address: $profile->address,
            city: $profile->city,
            postalCode: $profile->postalCode,
            country: $profile->country,
            email: $profile->email,
            phone: $profile->phone,
            peppolId: $peppolId,
        );
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function fromSnapshot(?array $snapshot): ?self
    {
        if ($snapshot === null || $snapshot === []) {
            return null;
        }

        return new self(
            name: self::text($snapshot, 'name') ?? '',
            ico: self::text($snapshot, 'ico'),
            dic: self::text($snapshot, 'dic'),
            vatId: self::text($snapshot, 'vat_id'),
            isVatPayer: (bool) ($snapshot['is_vat_payer'] ?? false),
            address: self::text($snapshot, 'address'),
            city: self::text($snapshot, 'city'),
            postalCode: self::text($snapshot, 'postal_code'),
            country: self::text($snapshot, 'country'),
            email: self::text($snapshot, 'email'),
            phone: self::text($snapshot, 'phone'),
            peppolId: self::text($snapshot, 'peppol_id'),
        );
    }

    /**
     * Snake_case keys, because that is the shape already written to the
     * columns and read back by the PDF templates. One home for it, for the
     * same reason SupplierProfile::toSnapshot() has one: the three issuing
     * points each carried their own copy of this list, and the copies had
     * already drifted apart once.
     *
     * @return array{name: string, ico: string|null, dic: string|null, vat_id: string|null, is_vat_payer: bool, address: string|null, city: string|null, postal_code: string|null, country: string|null, email: string|null, phone: string|null, peppol_id: string|null}
     */
    public function toSnapshot(): array
    {
        return [
            'name' => $this->name,
            'ico' => $this->ico,
            'dic' => $this->dic,
            'vat_id' => $this->vatId,
            'is_vat_payer' => $this->isVatPayer,
            'address' => $this->address,
            'city' => $this->city,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'email' => $this->email,
            'phone' => $this->phone,
            'peppol_id' => $this->peppolId,
        ];
    }

    /**
     * Which identifier names this party on a tax statement: the VAT number
     * when they are registered, otherwise the income-tax one, otherwise
     * nothing.
     */
    public function taxIdForStatement(): ?string
    {
        if ($this->isVatPayer && $this->vatId !== null && $this->vatId !== '') {
            return $this->vatId;
        }

        return $this->dic !== null && $this->dic !== '' ? $this->dic : null;
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
