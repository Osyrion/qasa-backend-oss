<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Enums\VatStatus;

/**
 * Who the account is *as a business* — the identity printed on an invoice and
 * filed in a VAT return.
 *
 * These fields live on the `users` row, which is why every module that renders
 * a document or a tax filing used to take a whole `User` just to read them,
 * and inherited the auth aggregate along with it. None of them is about
 * authentication: an IČO, a VAT ID and a postal address have nothing to do
 * with credentials, sessions or plans. Passing this value object instead is
 * what lets a builder say exactly what it needs.
 *
 * A snapshot, not a live handle. Reading it twice in one request gives the
 * same answer either way; writing goes through the account, never through
 * here.
 */
final readonly class SupplierProfile
{
    public function __construct(
        /** Printed name — company_name once set, otherwise the person's full name. */
        public string $name,
        public string $email,
        public ?string $phone,
        public ?string $ico,
        public ?string $dic,
        /** IČ DPH / VAT ID — distinct from $dic, which is the income-tax number. */
        public ?string $vatId,
        public VatStatus $vatStatus,
        public ?string $address,
        public ?string $city,
        public ?string $postalCode,
        /** ISO 3166-1 alpha-2; null until the account completes tax residency. */
        public ?string $country,
        public Currency $defaultCurrency,
        public ?string $website,
        public ?string $logoPath,
        public ?string $invoiceFooterText,
        /** Given name and surname, for filings that print them separately. */
        public string $firstName,
        public string $lastName,
    ) {}

    /**
     * The supplier as a document *snapshot* — the frozen copy an issued
     * invoice, quote or cash document stores so the paper never changes when
     * the account later moves office or registers for VAT.
     *
     * Snake_case keys because that is the shape already written to
     * `supplier_snapshot` columns and read back by the PDF services; this
     * method exists to stop the same fifteen lines being retyped at every
     * issuing point, which is how the quote snapshot ended up missing
     * `vat_status` while every other copy had it.
     *
     * `is_vat_payer` is derived rather than copied: the column of that name is
     * deprecated and kept in sync with `vat_status` on every save (see the
     * User model), so `vat_status` is the source of truth here as elsewhere.
     *
     * @return array{name: string, ico: string|null, dic: string|null, vat_id: string|null, is_vat_payer: bool, vat_status: string, address: string|null, city: string|null, postal_code: string|null, country: string|null, email: string, phone: string|null, website: string|null, logo_path: string|null, invoice_footer_text: string|null}
     */
    public function toSnapshot(): array
    {
        return [
            'name' => $this->name,
            'ico' => $this->ico,
            'dic' => $this->dic,
            'vat_id' => $this->vatId,
            'is_vat_payer' => $this->vatStatus->isVatPayer(),
            'vat_status' => $this->vatStatus->value,
            'address' => $this->address,
            'city' => $this->city,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'logo_path' => $this->logoPath,
            'invoice_footer_text' => $this->invoiceFooterText,
        ];
    }
}
