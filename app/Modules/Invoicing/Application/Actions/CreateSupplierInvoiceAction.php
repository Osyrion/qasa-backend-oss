<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\SupplierInvoiceData;
use App\Modules\Invoicing\Domain\Enums\SupplierVatRegime;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\Services\InvoiceNumberMask;
use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class CreateSupplierInvoiceAction
{
    public function __construct(
        private SupplierInvoiceRepositoryInterface $repository,
        private ClientRepositoryInterface $clients,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(SupplierInvoiceData $data, User $user): SupplierInvoice
    {
        $client = $this->clients->findByIdOrFail($data->client_id);

        if (! $client->isVendor()) {
            throw DomainException::because(__('invoicing.supplier_invoice.client_must_be_vendor'));
        }

        if ($data->vat_regime !== SupplierVatRegime::Domestic && $user->accountOwner()->vat_status === VatStatus::NonPayer) {
            throw DomainException::because(__('invoicing.supplier_invoice.self_assessment_requires_vat_status'));
        }

        return DB::transaction(function () use ($data, $user): SupplierInvoice {
            $userId = $user->accountOwnerId();

            $mask = new InvoiceNumberMask(
                $user->accountOwner()->supplier_invoice_number_mask
                    ?? config('invoicing.supplier_invoice_number_mask', 'DF-{YYYY}-{NNNN}')
            );

            $internalNumber = $this->repository->nextInternalNumber(
                userId: $userId,
                mask: $mask,
                start: $user->accountOwner()->supplier_invoice_number_start ?? 1,
            );

            $supplierInvoice = $this->repository->create([
                'user_id' => $userId,
                'client_id' => $data->client_id,
                'internal_number' => $internalNumber,
                'supplier_invoice_number' => $data->supplier_invoice_number,
                'variable_symbol' => $data->variable_symbol,
                'status' => 'draft',
                'vat_regime' => $data->vat_regime->value,
                'issued_at' => $data->issued_at,
                'taxable_supply_at' => $data->taxable_supply_at,
                'due_at' => $data->due_at,
                'received_at' => $data->received_at,
                'currency' => $data->currency->value,
                'exchange_rate' => $data->exchange_rate,
                'subtotal' => 0,
                'vat_amount' => 0,
                'total' => 0,
                'note' => $data->note,
            ]);

            foreach ($data->vat_lines as $line) {
                $supplierInvoice->vatLines()->create([
                    'vat_rate' => $line->vat_rate,
                    'base' => $line->base,
                    'vat_amount' => $line->vat_amount,
                    'sort_order' => $line->sort_order,
                ]);
            }

            $supplierInvoice->load('vatLines')->recalculateTotals()->save();

            return $supplierInvoice;
        });
    }
}
