<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Application\Contracts\RateResolverInterface;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class GenerateInvoiceFromOrderAction
{
    public function __construct(
        private CreateInvoiceAction $createAction,
        private RateResolverInterface $rateResolver,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Order $order, User $user, InvoiceData $data): Invoice
    {
        if ($order->isPersonal()) {
            throw DomainException::because(__('invoicing.cannot_invoice_personal_order'));
        }

        $billableItems = $order->items()->get();
        $billableEntries = $order->billableTimeEntries()->get();

        if ($billableItems->isEmpty() && $billableEntries->isEmpty()) {
            throw DomainException::because(__('invoicing.order_no_billable_items'));
        }

        // One query for the whole rate history of the scope — each entry is
        // then priced by the rate valid on its work date, so a future rate
        // change never reprices past or in-progress work.
        $rateSheet = $this->rateResolver->sheetFor($user, $order->client, $order);

        return DB::transaction(function () use ($order, $user, $data, $billableItems, $billableEntries, $rateSheet): Invoice {
            $invoice = $this->createAction->execute($data, $user);

            $sortOrder = 0;

            // Add order items
            foreach ($billableItems as $orderItem) {
                /** @var InvoiceItem $item */
                $item = $invoice->items()->make([
                    'order_item_id' => $orderItem->id,
                    'time_entry_id' => null,
                    'price_list_item_id' => $orderItem->price_list_item_id,
                    'description' => $orderItem->description,
                    'quantity' => $orderItem->quantity,
                    'unit' => $orderItem->unit,
                    'unit_price' => $orderItem->unit_price,
                    'vat_rate' => $orderItem->vat_rate,
                    'sort_order' => $sortOrder++,
                ]);
                $item->recalculate();
                $item->save();
            }

            // Add time entries — grouped as one line per entry
            foreach ($billableEntries as $entry) {
                $hours = round($entry->effectiveDurationHours(), 2);
                $rate = $entry->rate_override !== null
                    ? (float) $entry->rate_override
                    : ($rateSheet->rateOn($entry->started_at)->rate ?? (float) ($order->rate ?? 0));
                $vatRate = (float) $entry->vat_rate;

                /** @var InvoiceItem $item */
                $item = $invoice->items()->make([
                    'order_item_id' => null,
                    'time_entry_id' => $entry->id,
                    'description' => $entry->description ?? $order->name,
                    'quantity' => $hours,
                    'unit' => 'hod',
                    'unit_price' => $rate,
                    'vat_rate' => $vatRate,
                    'sort_order' => $sortOrder++,
                ]);
                $item->recalculate();
                $item->save();

                // Mark time entry as invoiced
                $entry->update(['is_invoiced' => true]);
            }

            // Recalculate invoice totals
            $invoice->load('items');
            $invoice->recalculateTotals()->save();

            return $invoice->fresh() ?? $invoice;
        });
    }
}
