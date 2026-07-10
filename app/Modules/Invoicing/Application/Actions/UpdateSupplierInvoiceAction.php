<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\SupplierInvoiceData;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateSupplierInvoiceAction
{
    public function __construct(
        private SupplierInvoiceRepositoryInterface $repository,
        private ClientRepositoryInterface $clients,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(SupplierInvoice $supplierInvoice, SupplierInvoiceData $data): SupplierInvoice
    {
        if (! $supplierInvoice->isEditable()) {
            throw DomainException::because(__('invoicing.supplier_invoice.only_draft_editable'));
        }

        $client = $this->clients->findByIdOrFail($data->client_id);

        if (! $client->isVendor()) {
            throw DomainException::because(__('invoicing.supplier_invoice.client_must_be_vendor'));
        }

        return DB::transaction(function () use ($supplierInvoice, $data): SupplierInvoice {
            $updated = $this->repository->update($supplierInvoice, [
                'client_id' => $data->client_id,
                'supplier_invoice_number' => $data->supplier_invoice_number,
                'variable_symbol' => $data->variable_symbol,
                'issued_at' => $data->issued_at,
                'taxable_supply_at' => $data->taxable_supply_at,
                'due_at' => $data->due_at,
                'received_at' => $data->received_at,
                'currency' => $data->currency->value,
                'exchange_rate' => $data->exchange_rate,
                'note' => $data->note,
            ]);

            $updated->vatLines()->delete();

            foreach ($data->vat_lines as $line) {
                $updated->vatLines()->create([
                    'vat_rate' => $line->vat_rate,
                    'base' => $line->base,
                    'vat_amount' => $line->vat_amount,
                    'sort_order' => $line->sort_order,
                ]);
            }

            $updated->load('vatLines')->recalculateTotals()->save();

            return $updated;
        });
    }
}
