<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
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
            $payment->delete();

            $invoice->unsetRelation('payments');

            if ($invoice->isPaid() && $invoice->balance() > 0) {
                $invoice->update([
                    'status' => $invoice->reminder_count > 0
                        ? InvoiceStatus::Reminded->value
                        : InvoiceStatus::Sent->value,
                ]);
            }
        });
    }
}
