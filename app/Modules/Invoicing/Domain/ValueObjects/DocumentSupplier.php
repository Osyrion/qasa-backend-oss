<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;

/**
 * The account's own business identity as a document froze it —
 * `supplier_snapshot`.
 *
 * The read side of {@see SupplierProfile::toSnapshot()}, which has had one
 * home for a while; this gives its readers one too. Same reasoning as
 * {@see DocumentParty}, and the same reason it is not SupplierProfile itself:
 * that value carries a live account's currency, logo, footer text and split
 * name, and it types VAT standing as a VatStatus enum that the earliest
 * snapshots do not record. A document keeps `is_vat_payer` either way, so that
 * is what this reads.
 */
final readonly class DocumentSupplier
{
    public function __construct(
        public string $name,
        public ?string $ico,
        public ?string $dic,
        /** IČ DPH / VAT ID — distinct from $dic, which is the income-tax number. */
        public ?string $vatId,
        public bool $isVatPayer,
        /** SupplierProfile's VatStatus value, when the snapshot is new enough to carry it. */
        public ?string $vatStatus,
        public ?string $address,
        public ?string $city,
        public ?string $postalCode,
        /** ISO 3166-1 alpha-2; null until the account completed tax residency. */
        public ?string $country,
        public ?string $email,
        public ?string $phone,
        public ?string $website,
        public ?string $logoPath,
        public ?string $invoiceFooterText,
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
            name: self::text($snapshot, 'name') ?? '',
            ico: self::text($snapshot, 'ico'),
            dic: self::text($snapshot, 'dic'),
            vatId: self::text($snapshot, 'vat_id'),
            isVatPayer: (bool) ($snapshot['is_vat_payer'] ?? false),
            vatStatus: self::text($snapshot, 'vat_status'),
            address: self::text($snapshot, 'address'),
            city: self::text($snapshot, 'city'),
            postalCode: self::text($snapshot, 'postal_code'),
            country: self::text($snapshot, 'country'),
            email: self::text($snapshot, 'email'),
            phone: self::text($snapshot, 'phone'),
            website: self::text($snapshot, 'website'),
            logoPath: self::text($snapshot, 'logo_path'),
            invoiceFooterText: self::text($snapshot, 'invoice_footer_text'),
        );
    }

    /**
     * The supplier reduced to the questions a ledger asks about any party.
     *
     * An accounting export describes both sides of a document with the same
     * six fields, and the extra things this value carries — website, logo,
     * footer text — are for a page rather than a ledger. Rather than a second
     * near-identical party builder in every exporter, the supplier says how it
     * looks as a party. The Peppol id is null by construction: it is where a
     * *counterparty* is reached, and the account's own is a credential, not a
     * snapshot.
     */
    public function asParty(): DocumentParty
    {
        return new DocumentParty(
            name: $this->name,
            ico: $this->ico,
            dic: $this->dic,
            vatId: $this->vatId,
            isVatPayer: $this->isVatPayer,
            address: $this->address,
            city: $this->city,
            postalCode: $this->postalCode,
            country: $this->country,
            email: $this->email,
            phone: $this->phone,
            peppolId: null,
        );
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
