<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;

/**
 * Who the counterparty on a document is — the identity printed opposite the
 * supplier and reported in a VAT statement.
 *
 * The same shape every module was reaching into `Clients\Domain\Models\Client`
 * for: a printed name, contact details, tax identifiers and an address. No
 * behaviour, no relations, nothing that belongs to Clients as a module — which
 * is exactly why passing this instead of the model lets Invoicing, Reports,
 * Banking and the rest stop depending on somebody else's aggregate.
 *
 * It is deliberately *not* the same type as SupplierProfile, even though the
 * fields overlap almost entirely: the supplier's VAT standing is a VatStatus
 * enum (payer / identified person / neither) while a client carries a plain
 * `is_vat_payer` boolean, and reconciling those two vocabularies is a decision
 * about the tax model, not about module boundaries. Worth doing — see
 * docs/plans/MODULE_BOUNDARY_MODEL_DEBT_PLAN.md — but not as a side effect of
 * this one.
 *
 * A snapshot, not a live handle: `invoices.client_snapshot` freezes exactly
 * these fields at issue, because a document must keep saying who it was issued
 * to even after the client record moves on.
 */
final readonly class PartyProfile
{
    public function __construct(
        /** Printed name — company name, or the person's, depending on client_type. */
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $ico,
        public ?string $dic,
        /** IČ DPH / VAT ID — distinct from $dic, which is the income-tax number. */
        public ?string $vatId,
        public bool $isVatPayer,
        public ?string $address,
        public ?string $city,
        public ?string $postalCode,
        /**
         * ISO 3166-1 alpha-2. Not nullable: a party on a document always has
         * a country, and `clients.country` is NOT NULL — unlike the supplier
         * side, where residency is completed only during onboarding.
         */
        public string $country,
        /** The currency documents for this party are denominated in. */
        public Currency $currency,
        /**
         * Domestic reverse-charge opt-in. A property of the counterparty
         * rather than of the document: whether this client may be invoiced
         * with the tax shifted to them is agreed once, not per invoice.
         */
        public bool $reverseChargeAllowed,
    ) {}
}
