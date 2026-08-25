<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\PayableSupplierInvoice;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/**
 * The payment side of a supplier invoice, for the module that pays it.
 *
 * Banking builds the batch, writes the bank file and checks the vendor's
 * account against the CZ VAT payer register — none of which needs the
 * document, only the payment line it produces. Reading it as
 * {@see PayableSupplierInvoice} means `supplier_invoices` can grow a column
 * without six classes in another module knowing.
 *
 * The two writes are here for the same reason: `handed_to_payment_at` and the
 * `account_verified_at`/`account_verification_result` pair live on our table,
 * and a module that updates them with its own query has taken a share in our
 * schema.
 */
interface SupplierInvoicePayments
{
    /**
     * Invoices that may be paid — received or booked — oldest due date first.
     *
     * A set crosses here, and it is bounded the way `InvoiceLookup` bounds
     * its own: one account's unpaid supplier invoices, printed one payment
     * line at a time rather than summed.
     *
     * @param  bool  $excludeHanded  drop the ones already in a live batch
     * @return list<PayableSupplierInvoice>
     */
    public function payable(bool $excludeHanded = false): array;

    /**
     * @throws ModelNotFoundException when the account does not own it
     */
    public function require(string $supplierInvoiceId): PayableSupplierInvoice;

    /**
     * All of them or none — a batch that silently skipped an id would freeze
     * fewer rows than the caller asked to pay.
     *
     * @param  list<string>  $supplierInvoiceIds
     * @return array<string, PayableSupplierInvoice> keyed by id
     *
     * @throws ModelNotFoundException when any id is unknown to the account
     */
    public function requireAll(array $supplierInvoiceIds): array;

    /**
     * @param  list<string>  $supplierInvoiceIds
     */
    public function markHandedToPayment(array $supplierInvoiceIds): void;

    /**
     * Clears the flag — for invoices dropped from a deleted batch that are
     * not in another live one.
     *
     * @param  list<string>  $supplierInvoiceIds
     */
    public function clearHandedToPayment(array $supplierInvoiceIds): void;

    /**
     * Records the outcome of a § 109 account check against the register.
     *
     * @param  string  $result  published|unpublished|unreliable
     */
    public function recordAccountVerification(string $supplierInvoiceId, string $result, Carbon $at): void;
}
