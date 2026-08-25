<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

/**
 * One open document, as a bank statement line is scored against it.
 *
 * Deliberately not an `InvoiceSummary` with more fields on it. Summary is
 * "what does this document say" — a reminder prints it, a notification names
 * it. This is "what could this payment be settling", and the two overlap only
 * by coincidence: matching wants the *outstanding* amount rather than the
 * total, the variable symbol nobody else reads, and the counterparty's bank
 * account, which is not part of any document. Widening the summary to cover
 * that would start it down the road to being the table again, and separate
 * values do not tend to drift back together.
 *
 * A collection of these is fine where a collection of `InvoiceSummary` would
 * not be: the set is one account's open invoices, not an aggregation over
 * every document ever issued.
 */
final readonly class PaymentMatchCandidate
{
    public function __construct(
        public string $invoiceId,
        /** Null until the document is issued; drafts have no number yet. */
        public ?string $number,
        public ?string $variableSymbol,
        public Currency $currency,
        /**
         * What is still outstanding, not the total — an exact match is a
         * statement line that settles the rest of the document, which for a
         * partly-paid one is not the same number.
         */
        public float $balance,
        public Carbon $dueAt,
        /**
         * The account this client last paid from, learned from a confirmed
         * match (see ClientBankAccountLearner). Null until they have paid
         * once, which is why it is a strong signal rather than a required
         * one.
         */
        public ?string $clientBankIban,
        /** The client's name today — matched against the statement line's counterparty. */
        public ?string $clientName,
    ) {}
}
