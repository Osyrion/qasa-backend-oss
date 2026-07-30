<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientUsageGuardInterface;
use App\Modules\Invoicing\Application\Contracts\RecordPaymentActionInterface;
use App\Modules\Invoicing\Application\DTOs\PaymentData;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Events\InvoicePaid;
use App\Modules\Invoicing\Domain\Events\PaymentRecorded;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class RecordPaymentAction implements RecordPaymentActionInterface
{
    public function __construct(
        private ClientUsageGuardInterface $usageGuard,
    ) {}

    /**
     * Record an incoming payment. Partial and over-payments are kept as
     * plain records — the payment status is always derived from the sum,
     * only a fully covered balance flips the invoice status to paid.
     *
     * $enforceUsageGuard is false only for the Stripe webhook: money that
     * has already been captured must never be rejected because the client
     * is currently free-tier read-only locked — see Q2 in
     * docs/plans/STRIPE_INVOICE_PAYMENTS_PLAN.md.
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice, PaymentData $data, bool $enforceUsageGuard = true): InvoicePayment
    {
        if ($enforceUsageGuard && $invoice->client !== null) {
            $this->usageGuard->ensureUsable($invoice->client);
        }

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
                'bank_reference' => $data->bank_reference,
                'stripe_payment_intent_id' => $data->stripe_payment_intent_id,
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
