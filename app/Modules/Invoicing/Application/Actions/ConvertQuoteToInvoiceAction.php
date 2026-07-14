<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class ConvertQuoteToInvoiceAction
{
    public function __construct(
        private CreateInvoiceAction $createInvoiceAction,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Quote $quote): Invoice
    {
        $this->assertConvertible($quote);

        return DB::transaction(function () use ($quote): Invoice {
            $quote->loadMissing(['items', 'user']);
            $issuedAt = now()->toDateString();
            $user = $quote->user;
            assert($user !== null);

            $invoice = $this->createInvoiceAction->execute(
                new InvoiceData(
                    client_id: $quote->client_id,
                    issued_at: $issuedAt,
                    due_at: now()->addDays(14)->toDateString(),
                    currency: $quote->currency,
                    discount_percent: $quote->discount_percent !== null ? (float) $quote->discount_percent : null,
                    note: $quote->note,
                    note_above: $quote->note_above,
                ),
                $user,
            );

            foreach ($quote->items as $item) {
                $invoiceItem = $invoice->items()->make([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'vat_rate' => $item->vat_rate,
                    'sort_order' => $item->sort_order,
                ]);
                $invoiceItem->recalculate();
                $invoiceItem->save();
            }

            $invoice->load('items');
            $invoice->recalculateTotals()->save();

            $quote->forceFill(['converted_invoice_id' => $invoice->id])->save();

            return $invoice;
        });
    }

    /**
     * @throws DomainException
     */
    private function assertConvertible(Quote $quote): void
    {
        if ($quote->isConverted()) {
            throw DomainException::because(__('invoicing.quote_already_converted'));
        }

        if (! in_array($quote->status, ['sent', 'accepted'], true)) {
            throw DomainException::because(__('invoicing.quote_convert_invalid_status'));
        }
    }
}
