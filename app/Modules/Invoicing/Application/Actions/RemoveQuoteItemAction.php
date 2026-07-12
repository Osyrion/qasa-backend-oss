<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Models\QuoteItem;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class RemoveQuoteItemAction
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Quote $quote, QuoteItem $item): void
    {
        if (! $quote->isEditable()) {
            throw DomainException::because(__('invoicing.quote_not_editable'));
        }

        DB::transaction(function () use ($quote, $item): void {
            $item->delete();

            $quote->load('items');
            $quote->recalculateTotals()->save();
        });
    }
}
