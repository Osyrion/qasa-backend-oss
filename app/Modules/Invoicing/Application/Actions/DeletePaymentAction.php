<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Events\PaymentDeleted;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class DeletePaymentAction
{
    /**
     * Remove a mis-recorded payment. If the invoice was auto-marked paid
     * and the balance reopens, revert to sent/issued (the reminder history
     * stays on the invoice either way).
     *
     * @throws Throwable
     */
    public function execute(Invoice $invoice, InvoicePayment $payment): void
    {
        DB::transaction(function () use ($invoice, $payment): void {
            // Re-read under a row lock, for the same reason and in the same
            // shape as RecordPaymentAction. $invoice is whatever the caller
            // bound at the top of the request, and the status this decision
            // turns on can have moved since — the bank sync auto-applies
            // matched payments (Provenance::AutoMatched) while a person is
            // deleting a mis-recorded one. Reading isPaid() off the stale
            // instance left an invoice marked paid with a balance still
            // outstanding; see DeletePaymentStalenessTest.
            $locked = Invoice::query()->lockForUpdate()->whereKey($invoice->getKey())->firstOrFail();

            $payment->delete();

            event(new PaymentDeleted($locked, $payment));

            $locked->unsetRelation('payments')->forgetPaymentsAggregate();

            if ($locked->isPaid() && $locked->balance() > 0) {
                $locked->update([
                    'status' => $locked->reminder_count > 0
                        ? InvoiceStatus::Reminded->value
                        : InvoiceStatus::Sent->value,
                ]);
            }

            // Hand the caller the state the transaction settled on rather
            // than the one it read before — it goes on to serialise it.
            $invoice->setRawAttributes($locked->getAttributes(), true);
            $invoice->unsetRelation('payments')->forgetPaymentsAggregate();
        });
    }
}
