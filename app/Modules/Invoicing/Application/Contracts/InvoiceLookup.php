<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\InvoiceSummary;
use App\Modules\Invoicing\Domain\ValueObjects\PaymentMatchCandidate;

/**
 * Reading an invoice from outside Invoicing.
 *
 * The counterpart to InvoiceSummary: a consumer that has an id gets the value,
 * never the model. Tenancy is the implementation's problem — the summary comes
 * back null for a document the current account cannot see, which is the same
 * answer a scoped query would have given.
 */
interface InvoiceLookup
{
    public function summary(string $invoiceId): ?InvoiceSummary;

    /**
     * The printed number alone, soft-deleted documents included.
     *
     * Separate from summary() because of that last part, not because it is
     * cheaper: a message about a document that arrives days late — a Peppol
     * access point's rejection, say — may well concern one that has been
     * deleted since, and that is exactly when the reader most needs to know
     * which document it was about. Null when there is nothing left to name,
     * and null again when the document has no number yet.
     */
    public function numberFor(string $invoiceId): ?string;

    /**
     * Who the document was issued to, as an id another module can carry to
     * `ClientDirectory` or `ClientBankAccountLearner`.
     *
     * `InvoiceSummary` deliberately prints the client's *name* — the snapshot
     * frozen at issue — which is the right answer for a reminder and the
     * wrong one for anything that has to act on the client record itself.
     * Null when the account cannot see the document; `invoices.client_id` is
     * NOT NULL, so "issued to nobody" is not a case.
     */
    public function clientIdFor(string $invoiceId): ?string;

    /**
     * Every open document the account could still be paid for, as values a
     * bank statement line can be scored against.
     *
     * The one place a *set* of documents crosses the boundary. That is safe
     * here and would not be in Reports: the set is one account's open
     * invoices, bounded by how many bills somebody has outstanding, and the
     * caller scores each one individually rather than summing them — the
     * thing SQL would have to do instead.
     *
     * The implementation must fetch the outstanding amounts in the same query
     * (`withSum`). Computing `balance` per candidate turns one query into one
     * per open invoice, which is the only real performance risk here.
     *
     * @return list<PaymentMatchCandidate>
     */
    public function openForMatching(string $ownerId): array;

    /**
     * Bank references the account has already recorded a payment for.
     *
     * The dedup signal for statement import: a line whose reference is in
     * here has been applied before and must not be applied again, no matter
     * which invoice it is aimed at the second time — hence keyed on the
     * account rather than on a document.
     *
     * @return list<string>
     */
    public function importedBankReferences(string $ownerId): array;

    /**
     * The same question for one reference. Separate from the list above
     * because the two callers are different shapes: previewing a statement
     * asks about a whole file at once, applying one confirmed pair asks about
     * a single line and should not pull the account's entire payment history
     * across to answer it.
     */
    public function hasImportedBankReference(string $ownerId, string $bankReference): bool;

    /**
     * Whether a Stripe payment intent has already been recorded against this
     * account.
     *
     * Stripe redelivers, so the webhook has to be idempotent — and the fact
     * that the answer lives in `invoice_payments.stripe_payment_intent_id` is
     * ours, not the handler's.
     */
    public function hasStripePaymentIntent(string $ownerId, string $paymentIntentId): bool;
}
