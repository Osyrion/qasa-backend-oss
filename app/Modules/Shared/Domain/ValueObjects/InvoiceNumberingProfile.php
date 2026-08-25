<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObjects;

/**
 * How the account numbers the documents it issues.
 *
 * The second concept pulled out of the `users` row after SupplierProfile, and
 * for the same reason: numbering is not authentication, but reading it meant
 * taking the auth aggregate — and taking it from the *right* row, since a team
 * member numbers documents with the owner's series. Resolving the owner is the
 * account's job, so it happens once, here, instead of at every `accountOwner()`
 * call a numbering site used to make.
 *
 * Three independent series: issued documents (invoices, proformas, credit
 * notes, stornos — the type prefix is InvoiceType's business, not this
 * value's), received supplier invoices, which get their own internal numbers,
 * and quotes.
 *
 * A mask stays nullable because "not configured" is a real state the caller
 * answers differently per series: issued documents fall back to the legacy
 * "{prefix}-{YYYY}-{NNN}" shape, received ones to a config default.
 */
final readonly class InvoiceNumberingProfile
{
    public function __construct(
        /** Prefix for the account's own invoice series; '' when unset. */
        public string $prefix,
        public ?string $mask,
        /** First number in the series — never null, 1 when unset. */
        public int $start,
        public ?string $supplierInvoiceMask,
        public int $supplierInvoiceStart,
        public ?string $quoteMask,
        /** First number in the quote series — never null, 1 when unset. */
        public int $quoteStart,
    ) {}
}
