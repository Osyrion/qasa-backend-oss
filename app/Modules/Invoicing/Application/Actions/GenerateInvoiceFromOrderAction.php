<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class GenerateInvoiceFromOrderAction
{
    public function __construct(
        private CreateInvoiceAction $createAction,
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

        if ($billableItems->isEmpty()) {
            throw DomainException::because(__('invoicing.order_no_billable_items'));
        }

        return DB::transaction(function () use ($user, $data, $billableItems): Invoice {
            $invoice = $this->createAction->execute($data, $user);

            $sortOrder = 0;

            foreach ($billableItems as $orderItem) {
                /** @var InvoiceItem $item */
                $item = $invoice->items()->make([
                    'order_item_id' => $orderItem->id,
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

            $invoice->load('items');
            $invoice->recalculateTotals()->save();

            return $invoice->fresh() ?? $invoice;
        });
    }
}
