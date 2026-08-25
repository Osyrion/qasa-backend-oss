<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Enums\CashDocumentType;
use App\Modules\Invoicing\Domain\ValueObjects\DatedAmount;

/**
 * What an account actually collected and actually paid, dated by when the
 * money moved.
 *
 * The cash-basis counterpart to {@see TurnoverAnalytics}, and the one place in
 * the analytic read API that hands over rows rather than sums. Turnover can
 * return what the database summed because the consumer only ever prints the
 * total; a tax return cannot, because every amount has to be converted into
 * the filing currency **at its own day's rate** before anything is added up.
 * Summing per currency in SQL would make a year of movements adopt a single
 * day's rate; summing per currency *and* day would keep the rates right but
 * round once per day instead of once per transaction, which moves the numbers
 * on a tax return by a cent or two for no benefit. So the rows cross, and the
 * caller — who owns the conversion — does the adding.
 *
 * The set is bounded by one account's movements in one year, which is the
 * same set that was already being loaded into memory when Taxation queried
 * these four tables itself.
 *
 * Every method is dated by the column that means "when the money moved", and
 * it is a different column each time — `invoice_payments.paid_at`,
 * `supplier_invoices.paid_at`, `expenses.date`, `cash_documents.issued_at`.
 * That difference is the schema knowledge this contract exists to keep inside
 * Invoicing.
 */
interface CashBasisAnalytics
{
    /**
     * Payments received against issued invoices, in the invoice's currency.
     *
     * @return list<DatedAmount>
     */
    public function collectedInYear(string $ownerId, int $year): array;

    /**
     * Supplier invoices settled during the year, at their full total — a
     * partial payment is not modelled on the received side.
     *
     * @return list<DatedAmount>
     */
    public function supplierInvoicesPaidInYear(string $ownerId, int $year): array;

    /**
     * Expenses recorded for the year, dated by `date`.
     *
     * @return list<DatedAmount>
     */
    public function expensesInYear(string $ownerId, int $year): array;

    /**
     * Cash documents that stand alone — the ones nothing else already counts.
     *
     * A receipt carrying `invoice_payment_id` or `expense_id` is a piece of
     * paper for money already counted through that payment or expense, so
     * including it would double every cash-paid invoice. Only the standalone
     * ones are new information, and a reversal cancels its original because it
     * is itself a standalone document of the opposite type.
     *
     * @return list<DatedAmount>
     */
    public function standaloneCashInYear(string $ownerId, int $year, CashDocumentType $type): array;
}
