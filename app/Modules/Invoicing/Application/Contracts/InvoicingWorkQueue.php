<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\DocumentTally;

/**
 * What is waiting for the account owner's attention in Invoicing, as counts
 * and sums.
 *
 * Not a report: each method answers "how many, worth how much", and an empty
 * tally means nothing needs doing — which is itself the useful signal. What
 * counts as waiting is Invoicing's definition, not the caller's, which is the
 * whole reason it is asked rather than reproduced.
 */
interface InvoicingWorkQueue
{
    /**
     * Invoices sent or reminded, past their due date, with money still owed.
     *
     * Narrower than "open": an issued invoice the client has never been shown
     * is not something to chase, so only sent and reminded ones count.
     */
    public function overdueInvoices(string $ownerId): DocumentTally;

    /**
     * Proformas paid in full but never settled into a tax document — money
     * received against a document that does not yet exist.
     */
    public function unsettledProformas(string $ownerId): DocumentTally;

    /**
     * Documents in the invoice inbox still waiting to be turned into supplier
     * invoices.
     */
    public function pendingInboxCount(string $ownerId): int;

    /**
     * Bank references already recorded against a payment.
     *
     * The one question here that is not a count: a review queue built from
     * bank statement lines must not re-offer a line somebody already applied,
     * and whether it was applied is a fact about payments, not about the
     * statement.
     *
     * @return list<string>
     */
    public function settledBankReferences(string $ownerId): array;
}
