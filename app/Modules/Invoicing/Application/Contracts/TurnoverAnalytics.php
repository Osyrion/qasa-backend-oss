<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\ClientTurnover;
use App\Modules\Invoicing\Domain\ValueObjects\CurrencyTotal;
use App\Modules\Invoicing\Domain\ValueObjects\MonthlyTurnover;

/**
 * What an account turned over, as sums.
 *
 * The second piece of Invoicing's analytic read API after
 * {@see ReceivablesAnalytics}, and the one that shows where the two halves of
 * the pattern divide. Receivables hands over *rows* because the set is one
 * account's open documents — bounded, and every consumer buckets them one at
 * a time. Turnover cannot: the caller picks the range, so the row count grows
 * with the account's history and only the aggregate may cross. Every method
 * here therefore returns something the database already summed.
 *
 * Dates are `YYYY-MM-DD` strings compared against the column each method
 * names, which is not the same column in each case — issued_at for what was
 * invoiced, paid_at for what was collected, date for an expense. That
 * difference is exactly the schema knowledge this contract exists to keep
 * inside Invoicing.
 */
interface TurnoverAnalytics
{
    /**
     * Issued documents by month, dated by `issued_at`. Drafts excluded;
     * credit notes and storno documents already carry a negative total, so
     * they net against the invoices they correct.
     *
     * @return list<MonthlyTurnover>
     */
    public function invoicedByMonth(string $ownerId, string $from, string $to, ?string $clientId = null): array;

    /**
     * Cash actually received by month, dated by the payment's `paid_at`. A
     * client filter applies to the invoice the payment settles.
     *
     * @return list<MonthlyTurnover>
     */
    public function collectedByMonth(string $ownerId, string $from, string $to, ?string $clientId = null): array;

    /**
     * Expenses by month, dated by `date`. No client filter: an expense is not
     * attributable to one, and the caller decides what to do about that.
     *
     * @return list<MonthlyTurnover>
     */
    public function expensesByMonth(string $ownerId, string $from, string $to): array;

    /**
     * The same issued documents as invoicedByMonth(), grouped by client and
     * currency instead of by month.
     *
     * @return list<ClientTurnover>
     */
    public function invoicedByClient(string $ownerId, string $from, string $to): array;

    /**
     * Cash received over one window, per currency and nothing else — the
     * baseline a forecast smooths against, where months would be the wrong
     * granularity because the window is counted in weeks.
     *
     * @return list<CurrencyTotal>
     */
    public function collectedBetween(string $ownerId, string $from, string $to): array;
}
