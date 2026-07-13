<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Events\InvoiceCreated;
use App\Modules\Invoicing\Domain\Events\InvoicePaid;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns a fully paid proforma into an ordinary tax invoice: a new "invoice"
 * type document with the proforma's items copied in full (the deposit is
 * not subtracted as a line — it is carried over as payment rows instead, so
 * revenue statistics, which read subtotal off invoice/credit_note types and
 * exclude proforma, record the sale exactly once). The new invoice is
 * issued immediately and created already fully paid.
 */
readonly class SettleProformaAction
{
    public function __construct(
        private InvoiceRepositoryInterface $repository,
        private IssueInvoiceAction $issueAction,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $proforma, User $user): Invoice
    {
        if ($proforma->type !== InvoiceType::Proforma) {
            throw DomainException::because(__('invoicing.settle_only_proforma'));
        }

        if (! $proforma->isPaid()) {
            throw DomainException::because(__('invoicing.settle_requires_paid_proforma'));
        }

        if ($proforma->settled_invoice_id !== null) {
            throw DomainException::because(__('invoicing.proforma_already_settled'));
        }

        return DB::transaction(function () use ($proforma, $user): Invoice {
            $proforma->loadMissing(['items', 'payments']);

            $lastPaymentAt = $proforma->payments->max('paid_at');

            $number = $this->repository->nextInvoiceNumber(
                userId: $proforma->user_id,
                mask: InvoiceType::Invoice->numberMask($user),
                start: $user->accountOwner()->invoice_number_start ?? 1,
            );

            $invoice = $this->repository->create([
                'user_id' => $proforma->user_id,
                'client_id' => $proforma->client_id,
                'invoice_number' => $number,
                'type' => InvoiceType::Invoice->value,
                'related_invoice_id' => $proforma->id,
                'status' => InvoiceStatus::Draft->value,
                'issued_at' => now()->toDateString(),
                'taxable_supply_at' => ($lastPaymentAt?->toDateString()) ?? now()->toDateString(),
                'due_at' => now()->toDateString(),
                'bank_account_id' => $proforma->bank_account_id,
                'currency' => $proforma->currency->value,
                'exchange_rate_snapshot' => $proforma->exchange_rate_snapshot,
                'subtotal' => 0,
                'discount_percent' => $proforma->discount_percent,
                'discount_amount' => 0,
                'reverse_charge' => $proforma->reverse_charge,
                'reverse_charge_mode' => $proforma->reverse_charge_mode?->value,
                'vat_amount' => 0,
                'total' => 0,
                'note' => $proforma->note,
            ]);

            foreach ($proforma->items as $item) {
                $invoice->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'vat_rate' => $item->vat_rate,
                    'vat_amount' => 0,
                    'total_excl_vat' => 0,
                    'total_incl_vat' => 0,
                    'sort_order' => $item->sort_order,
                ])->recalculate()->save();
            }

            $invoice->refresh()->recalculateTotals()->save();

            $invoice = $this->issueAction->execute($invoice);

            foreach ($proforma->payments as $payment) {
                $invoice->payments()->create([
                    'amount' => $payment->amount,
                    'paid_at' => $payment->paid_at,
                    'method' => $payment->method,
                    'note' => __('invoicing.settled_from_proforma', ['number' => $proforma->invoice_number]),
                ]);
            }

            $invoice = $this->repository->update($invoice, ['status' => InvoiceStatus::Paid->value]);

            $proforma->update(['settled_invoice_id' => $invoice->id]);

            event(new InvoiceCreated($invoice));
            event(new InvoicePaid($invoice));

            return $invoice;
        });
    }
}
