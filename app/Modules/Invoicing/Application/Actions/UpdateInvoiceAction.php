<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateInvoiceAction
{
    public function __construct(
        private InvoiceRepositoryInterface $repository,
    ) {}

    /**
     * Update a draft invoice's header fields. The invoice number and type
     * are fixed at creation; items have their own endpoints.
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice, InvoiceData $data): Invoice
    {
        if (! $invoice->isEditable()) {
            throw DomainException::because(__('invoicing.only_draft_editable'));
        }

        return DB::transaction(function () use ($invoice, $data): Invoice {
            $updated = $this->repository->update($invoice, [
                'client_id' => $data->client_id,
                'issued_at' => $data->issued_at,
                'taxable_supply_at' => $data->taxable_supply_at,
                'due_at' => $data->due_at,
                'variable_symbol' => $data->variable_symbol,
                'bank_account_id' => $data->bank_account_id,
                'currency' => $data->currency->value,
                'discount_percent' => $data->discount_percent,
                'note' => $data->note,
                'note_above' => $data->note_above,
            ]);

            $updated->refresh()->recalculateTotals()->save();

            return $updated;
        });
    }
}
