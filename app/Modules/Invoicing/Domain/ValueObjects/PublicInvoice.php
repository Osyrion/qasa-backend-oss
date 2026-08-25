<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;

/**
 * An invoice as the online-payment path sees it — the public link a customer
 * opens, the Checkout session built from it, and the webhook that comes back.
 *
 * Deliberately not {@see InvoiceSummary}: that one is the *account's* view of
 * a document it owns (who it is for, when it is due, how many reminders have
 * gone out) and every read of it runs under the account scope. This one is
 * what an unauthenticated stranger holding a link may cause to happen, so it
 * carries the balance still owed and the three facts that decide whether
 * paying it is even a coherent request — and nothing about the customer at
 * all.
 */
final readonly class PublicInvoice
{
    public function __construct(
        public string $id,
        /** The account that issued it — the connected Stripe account to charge on. */
        public string $ownerId,
        /** Null on a draft, which has no number and no public link either. */
        public ?string $number,
        /** What a payment line calls it: the document type plus its number. */
        public string $paymentDescription,
        /** draft|sent|paid|overdue|cancelled — what the page and the webhook print. */
        public string $status,
        public Currency $currency,
        /** The document's face value. */
        public float $total,
        /** Still owed, in the document's own currency. */
        public float $balance,
        /** The customer-facing URL, or null when the link is switched off. */
        public ?string $publicUrl,
        public bool $isCancelled,
        public bool $isCreditNote,
    ) {}

    /**
     * Whether asking a customer to pay this is a coherent request at all.
     *
     * A cancelled document is not owed, a credit note owes money the other
     * way, and a settled one has nothing left — all three would otherwise
     * reach Stripe as a charge somebody has to refund.
     */
    public function isPayable(): bool
    {
        return $this->balance > 0 && ! $this->isCancelled && ! $this->isCreditNote;
    }
}
