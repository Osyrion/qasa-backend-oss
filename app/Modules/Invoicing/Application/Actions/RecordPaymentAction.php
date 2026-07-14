<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\DTOs\PaymentData;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Events\InvoicePaid;
use App\Modules\Invoicing\Domain\Events\PaymentRecorded;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class RecordPaymentAction
{
    /**
     * Record an incoming payment. Partial and over-payments are kept as
     * plain records — the payment status is always derived from the sum,
     * only a fully covered balance flips the invoice status to paid.
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice, PaymentData $data): InvoicePayment
    {
        if (! $invoice->statusEnum()->isOpen() && ! $invoice->isPaid()) {
            throw DomainException::because(
                __('invoicing.payment_requires_open_invoice', ['status' => $invoice->statusEnum()->label()])
            );
        }

        if ($invoice->isCreditNote()) {
            throw DomainException::because(__('invoicing.payment_not_for_credit_note'));
        }

        return DB::transaction(function () use ($invoice, $data): InvoicePayment {
            /** @var InvoicePayment $payment */
            $payment = $invoice->payments()->create([
                'amount' => $data->amount,
                'paid_at' => $data->paid_at,
                'method' => $data->method,
                'note' => $data->note,
            ]);

            $invoice->unsetRelation('payments');

            if ($invoice->balance() <= 0 && ! $invoice->isPaid()) {
                $invoice->update(['status' => InvoiceStatus::Paid->value]);
                event(new InvoicePaid($invoice));
            }

            event(new PaymentRecorded($invoice, $payment));

            return $payment;
        });
    }
}
