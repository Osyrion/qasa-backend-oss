<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\DTOs\InvoiceItemData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Shared\Enums\ItemUnit;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class AddInvoiceItemAction
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice, InvoiceItemData $data): InvoiceItem
    {
        if (! $invoice->isEditable()) {
            throw DomainException::because(__('invoicing.items_only_for_draft'));
        }

        return DB::transaction(function () use ($invoice, $data): InvoiceItem {
            $unit = ItemUnit::tryFrom($data->unit)?->value ?? $data->unit;

            /** @var InvoiceItem $item */
            $item = $invoice->items()->make([
                'order_item_id' => $data->order_item_id,
                'time_entry_id' => $data->time_entry_id,
                'price_list_item_id' => $data->price_list_item_id,
                'description' => $data->description,
                'quantity' => $data->quantity,
                'unit' => $unit,
                'unit_price' => $data->unit_price,
                'vat_rate' => $data->vat_rate,
                'sort_order' => $data->sort_order,
            ]);

            $item->recalculate();
            $item->save();

            // Recalculate invoice totals
            $invoice->load('items');
            $invoice->recalculateTotals()->save();

            return $item;
        });
    }
}
