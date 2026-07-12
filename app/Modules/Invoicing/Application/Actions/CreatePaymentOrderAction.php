<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\PaymentOrderData;
use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceStatus;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class CreatePaymentOrderAction
{
    public function __construct(
        private UpdateSupplierInvoiceStatusAction $updateStatusAction,
    ) {}

    /**
     * Creates the batch with frozen rows and marks the invoices as handed
     * to payment. A past due date is bumped to today (the ABO format cannot
     * carry one) — `due_date_adjusted` in the result reports the bump.
     *
     * @return array{order: PaymentOrder, due_date_adjusted: bool}
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(PaymentOrderData $data, User $user): array
    {
        // User-scoped resolution: a foreign or unknown account/invoice is a 404.
        /** @var BankAccount $bankAccount */
        $bankAccount = BankAccount::query()->findOrFail($data->bank_account_id);

        /** @var Collection<int, SupplierInvoice> $invoices */
        $invoices = SupplierInvoice::query()
            ->with('client')
            ->whereIn('id', $data->supplier_invoice_ids)
            ->get();

        if ($invoices->count() !== count($data->supplier_invoice_ids)) {
            throw (new ModelNotFoundException)->setModel(SupplierInvoice::class);
        }

        foreach ($invoices as $invoice) {
            $this->assertPayable($invoice, $bankAccount);
        }

        $dueDate = Carbon::parse($data->due_date)->startOfDay();
        $dueDateAdjusted = $dueDate->isPast() && ! $dueDate->isToday();

        if ($dueDateAdjusted) {
            $dueDate = Carbon::today();
        }

        $order = DB::transaction(function () use ($data, $user, $bankAccount, $invoices, $dueDate): PaymentOrder {
            /** @var PaymentOrder $order */
            $order = PaymentOrder::create([
                'user_id' => $user->accountOwnerId(),
                'bank_account_id' => $bankAccount->id,
                'payer_snapshot' => $bankAccount->toSnapshot(),
                'currency' => $bankAccount->currency->value,
                'due_date' => $dueDate->toDateString(),
                'constant_symbol' => $data->constant_symbol,
                'note' => $data->note,
                'items_count' => $invoices->count(),
                'total_amount' => round((float) $invoices->sum(fn (SupplierInvoice $i): float => (float) $i->total), 2),
                'marked_paid' => $data->mark_paid,
            ]);

            // Snapshot rows in the order the ids were submitted.
            $byId = $invoices->keyBy('id');

            foreach ($data->supplier_invoice_ids as $index => $id) {
                /** @var SupplierInvoice $invoice */
                $invoice = $byId[$id];

                $order->items()->create([
                    'supplier_invoice_id' => $invoice->id,
                    'vendor_name' => $this->vendorName($invoice),
                    'supplier_invoice_number' => $invoice->supplier_invoice_number,
                    'account_number' => $invoice->vendor_account_number,
                    'bank_code' => $invoice->vendor_bank_code,
                    'iban' => $invoice->vendor_iban,
                    'bic' => $invoice->vendor_bic,
                    'variable_symbol' => $invoice->variable_symbol,
                    'amount' => $invoice->total,
                    'sort_order' => $index,
                ]);
            }

            SupplierInvoice::query()
                ->whereIn('id', $data->supplier_invoice_ids)
                ->update(['handed_to_payment_at' => now()]);

            if ($data->mark_paid) {
                foreach ($invoices as $invoice) {
                    $this->updateStatusAction->execute($invoice, SupplierInvoiceStatus::Paid);
                }
            }

            return $order;
        });

        return ['order' => $order->load('items'), 'due_date_adjusted' => $dueDateAdjusted];
    }

    /**
     * @throws DomainException
     */
    private function assertPayable(SupplierInvoice $invoice, BankAccount $bankAccount): void
    {
        if (! in_array($invoice->status, ['received', 'booked'], true)) {
            throw DomainException::because(__('invoicing.payment_order_invoice_not_payable', [
                'number' => $invoice->internal_number,
            ]));
        }

        if (! $invoice->hasPaymentAccount()) {
            throw DomainException::because(__('invoicing.payment_order_account_missing', [
                'number' => $invoice->internal_number,
            ]));
        }

        if ($invoice->currency !== $bankAccount->currency) {
            throw DomainException::because(__('invoicing.payment_order_currency_mismatch', [
                'number' => $invoice->internal_number,
                'currency' => $invoice->currency->value,
                'payer_currency' => $bankAccount->currency->value,
            ]));
        }
    }

    private function vendorName(SupplierInvoice $invoice): string
    {
        $snapshot = $invoice->vendor_snapshot;

        if ($snapshot !== null && ! empty($snapshot['name'])) {
            return (string) $snapshot['name'];
        }

        return $invoice->client->display_name ?? '';
    }
}
