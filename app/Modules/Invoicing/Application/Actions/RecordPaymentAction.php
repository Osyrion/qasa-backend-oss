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
use App\Modules\Shared\Enums\Provenance;
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
    public function execute(Invoice $invoice, PaymentData $data, bool $enforceUsageGuard = true, Provenance $provenance = Provenance::Manual): InvoicePayment
    {
        if ($enforceUsageGuard && $invoice->client !== null) {
            $this->usageGuard->ensureUsable($invoice->client);
        }

        if ($invoice->isCreditNote()) {
            throw DomainException::because(__('invoicing.payment_not_for_credit_note'));
        }

        return DB::transaction(function () use ($invoice, $data, $provenance): InvoicePayment {
            // Re-read under a row lock before deciding anything. This action
            // is reached from the Stripe webhook, the bank matcher and a
            // person clicking "mark paid", so $invoice can already be stale:
            // its status may have been settled or cancelled since, and the
            // balance below has to be computed against payments nobody else
            // is inserting concurrently. Same reasoning, same shape as
            // UpdateInvoiceStatusAction.
            $locked = Invoice::query()->lockForUpdate()->whereKey($invoice->getKey())->firstOrFail();

            if (! $locked->statusEnum()->isOpen() && ! $locked->isPaid()) {
                throw DomainException::because(
                    __('invoicing.payment_requires_open_invoice', ['status' => $locked->statusEnum()->label()])
                );
            }

            /** @var InvoicePayment $payment */
            $payment = $locked->payments()->create([
                'amount' => $data->amount,
                'paid_at' => $data->paid_at,
                'method' => $data->method,
                'provenance' => $provenance->value,
                'note' => $data->note,
                'bank_reference' => $data->bank_reference,
                'stripe_payment_intent_id' => $data->stripe_payment_intent_id,
            ]);

            $locked->unsetRelation('payments')->forgetPaymentsAggregate();

            if ($locked->balance() <= 0 && ! $locked->isPaid()) {
                $locked->update(['status' => InvoiceStatus::Paid->value]);
                event(new InvoicePaid($locked));
            }

            event(new PaymentRecorded($locked, $payment));

            // The caller still holds the pre-lock instance and goes on to
            // serialise it — hand it the state the transaction settled on
            // rather than the one it read before.
            $invoice->setRawAttributes($locked->getAttributes(), true);
            $invoice->unsetRelation('payments')->forgetPaymentsAggregate();

            return $payment;
        });
    }
}
