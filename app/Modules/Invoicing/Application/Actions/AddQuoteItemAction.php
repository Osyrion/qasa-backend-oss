<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\DTOs\QuoteItemData;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Models\QuoteItem;
use App\Modules\Shared\Enums\ItemUnit;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class AddQuoteItemAction
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Quote $quote, QuoteItemData $data): QuoteItem
    {
        if (! $quote->isEditable()) {
            throw DomainException::because(__('invoicing.quote_not_editable'));
        }

        return DB::transaction(function () use ($quote, $data): QuoteItem {
            $unit = ItemUnit::tryFrom($data->unit)->value ?? $data->unit;

            /** @var QuoteItem $item */
            $item = $quote->items()->make([
                'description' => $data->description,
                'quantity' => $data->quantity,
                'unit' => $unit,
                'unit_price' => $data->unit_price,
                'vat_rate' => $data->vat_rate,
                'sort_order' => $data->sort_order,
            ]);

            $item->recalculate();
            $item->save();

            $quote->load('items');
            $quote->recalculateTotals()->save();

            return $item;
        });
    }
}
