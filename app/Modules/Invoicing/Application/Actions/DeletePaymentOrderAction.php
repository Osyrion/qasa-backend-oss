<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use App\Modules\Invoicing\Domain\Models\PaymentOrderItem;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class DeletePaymentOrderAction
{
    /**
     * Soft-deletes the batch and clears the handed-to-payment flag on its
     * invoices — unless an invoice is also part of another live batch.
     *
     * @throws Throwable
     */
    public function execute(PaymentOrder $paymentOrder): void
    {
        DB::transaction(function () use ($paymentOrder): void {
            $invoiceIds = $paymentOrder->items()
                ->whereNotNull('supplier_invoice_id')
                ->pluck('supplier_invoice_id')
                ->all();

            $paymentOrder->delete();

            if ($invoiceIds === []) {
                return;
            }

            $stillHandedIds = PaymentOrderItem::query()
                ->whereIn('supplier_invoice_id', $invoiceIds)
                ->whereHas('paymentOrder') // soft-deleted orders drop out here
                ->pluck('supplier_invoice_id')
                ->all();

            SupplierInvoice::query()
                ->whereIn('id', array_diff($invoiceIds, $stillHandedIds))
                ->update(['handed_to_payment_at' => null]);
        });
    }
}
