<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

/**
 * A supplier invoice as the payment side reads it — the builder screen that
 * picks what to pay, the batch that freezes rows, and the § 109 account check
 * before the money leaves.
 *
 * Deliberately not a general "supplier invoice summary": everything here is
 * either printed on a payment line or decides whether a line may exist. What
 * the document *says* — its items, its VAT regime, its attachments — is not
 * the payment side's business and is not published.
 *
 * The vendor's name, tax id and country are already resolved: each is taken
 * from the frozen `vendor_snapshot` where it has one and from the live client
 * otherwise. That fallback used to be written out at three call sites in
 * Banking, which meant three chances to read the live record for a vendor the
 * document had already frozen.
 */
final readonly class PayableSupplierInvoice
{
    public function __construct(
        public string $id,
        /** Our own number for it, e.g. DF-2026-001. */
        public string $internalNumber,
        /** The number the vendor issued it under. */
        public string $supplierInvoiceNumber,
        public string $vendorName,
        /** DIČ, for the CZ VAT payer register. */
        public ?string $vendorTaxId,
        /** ISO 3166-1 alpha-2 — the register only covers CZ. */
        public ?string $vendorCountry,
        public ?Carbon $dueAt,
        public float $total,
        public Currency $currency,
        public ?string $variableSymbol,
        public VendorPaymentAccount $account,
        /** When it was last put into a payment batch, or null. */
        public ?Carbon $handedToPayment,
        public string $status,
    ) {}

    /** Received or booked — anything else is not ours to pay yet, or already paid. */
    public function isPayable(): bool
    {
        return in_array($this->status, ['received', 'booked'], true);
    }

    public function isOverdue(): bool
    {
        return $this->dueAt !== null && $this->dueAt->isPast() && ! $this->dueAt->isToday();
    }
}
