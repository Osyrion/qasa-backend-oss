<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\Contracts\ExchangeRateServiceInterface;
use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Orders\Application\Contracts\BillableWorkProviderInterface;
use App\Modules\Orders\Application\Contracts\OrderLookup;
use App\Modules\Orders\Application\Contracts\OrderRateResolverInterface;
use App\Modules\Orders\Domain\ValueObjects\OrderSummary;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class GenerateInvoiceFromOrderAction
{
    public function __construct(
        private CreateInvoiceAction $createAction,
        private OrderRateResolverInterface $rateResolver,
        private OrderLookup $orders,
        private BillableWorkProviderInterface $billableWork,
        private ExchangeRateServiceInterface $exchangeRates,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(OrderSummary $order, Account&ProvidesPlanEntitlements&ProvidesSupplierProfile $user, InvoiceData $data): Invoice
    {
        if ($order->isPersonal()) {
            throw DomainException::because(__('invoicing.cannot_invoice_personal_order'));
        }

        $billableItems = $this->orders->itemsFor($order->id);
        $billableEntries = $this->billableWork->billableWorkFor($order->id);

        if ($billableItems === [] && $billableEntries === []) {
            throw DomainException::because(__('invoicing.order_no_billable_items'));
        }

        // One query for the whole rate history of the scope — each entry is
        // then priced by the rate valid on its work date, so a future rate
        // change never reprices past or in-progress work.
        $rateSheet = $this->rateResolver->sheetFor($user, $order->clientId, $order->id);

        // Resolved before the transaction: this may hit the ČNB HTTP API and
        // must not run inside a DB transaction. Unlike the issue-time snapshot,
        // a missing rate here blocks generation — the invoiced amounts would
        // otherwise be wrong.
        $orderCurrency = $order->currency;
        $conversionRate = null;

        if ($orderCurrency !== $data->currency) {
            $conversionRate = $this->exchangeRates->getConversionRateOrFetchCnb(
                $orderCurrency,
                $data->currency,
                $user->accountOwnerId(),
                now()->toDateString(),
            );

            if ($conversionRate === null) {
                throw DomainException::because(__('invoicing.conversion_rate_unavailable', [
                    'from' => $orderCurrency->value,
                    'to' => $data->currency->value,
                ]));
            }
        }

        return DB::transaction(function () use ($order, $user, $data, $billableItems, $billableEntries, $rateSheet, $orderCurrency, $conversionRate): Invoice {
            $invoice = $this->createAction->execute($data, $user);

            $sortOrder = 0;

            // Add order items
            foreach ($billableItems as $orderItem) {
                $unitPrice = $conversionRate !== null
                    ? Decimal::money(Decimal::of($orderItem->unitPrice)->multipliedBy(Decimal::of($conversionRate)))
                    : $orderItem->unitPrice;

                /** @var InvoiceItem $item */
                $item = $invoice->items()->make([
                    'order_item_id' => $orderItem->id,
                    'time_entry_id' => null,
                    'price_list_item_id' => $orderItem->priceListItemId,
                    'description' => $orderItem->description,
                    'quantity' => $orderItem->quantity,
                    'unit' => $orderItem->unit,
                    'unit_price' => $unitPrice,
                    'vat_rate' => $orderItem->vatRate,
                    'sort_order' => $sortOrder++,
                ]);
                $item->recalculate();
                $item->save();
            }

            // Add tracked work — grouped as one line per unit of work
            foreach ($billableEntries as $entry) {
                $rate = $entry->rateOverride
                    ?? ($rateSheet->hourlyRateOn($entry->workedAt) ?? ($order->rate ?? 0.0));

                if ($conversionRate !== null) {
                    $rate = (float) Decimal::money(Decimal::of($rate)->multipliedBy(Decimal::of($conversionRate)));
                }

                /** @var InvoiceItem $item */
                $item = $invoice->items()->make([
                    'order_item_id' => null,
                    'time_entry_id' => $entry->id,
                    'description' => $entry->description ?? $order->name,
                    'quantity' => $entry->hours,
                    'unit' => 'hod',
                    'unit_price' => $rate,
                    'vat_rate' => $entry->vatRate,
                    'sort_order' => $sortOrder++,
                ]);
                $item->recalculate();
                $item->save();

                $this->billableWork->markInvoiced($entry->id);
            }

            if ($conversionRate !== null) {
                $invoice->converted_from_currency = $orderCurrency->value;
                $invoice->conversion_rate = $conversionRate;
            }

            // Recalculate invoice totals
            $invoice->load('items');
            $invoice->recalculateTotals()->save();

            return $invoice->fresh() ?? $invoice;
        });
    }
}
