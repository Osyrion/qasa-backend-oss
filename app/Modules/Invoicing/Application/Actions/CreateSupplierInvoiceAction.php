<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Application\Contracts\ClientUsageGuardInterface;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\SupplierInvoiceData;
use App\Modules\Invoicing\Domain\Enums\SupplierVatRegime;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\ValueObjects\InvoiceNumberMask;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesInvoiceNumbering;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use App\Modules\Shared\Enums\Provenance;
use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class CreateSupplierInvoiceAction
{
    public function __construct(
        private ClientUsageGuardInterface $usageGuard,
        private SupplierInvoiceRepositoryInterface $repository,
        private ClientRepositoryInterface $clients,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(SupplierInvoiceData $data, Account&ProvidesInvoiceNumbering&ProvidesSupplierProfile $user, Provenance $provenance = Provenance::Manual): SupplierInvoice
    {
        $client = $this->clients->findByIdOrFail($data->client_id);

        if (! $client->isVendor()) {
            throw DomainException::because(__('invoicing.supplier_invoice.client_must_be_vendor'));
        }

        $this->usageGuard->ensureUsable($client);

        if ($data->vat_regime !== SupplierVatRegime::Domestic && $user->supplierProfile()->vatStatus === VatStatus::NonPayer) {
            throw DomainException::because(__('invoicing.supplier_invoice.self_assessment_requires_vat_status'));
        }

        return DB::transaction(function () use ($data, $user, $provenance): SupplierInvoice {
            $userId = $user->accountOwnerId();

            $numbering = $user->invoiceNumbering();

            $mask = new InvoiceNumberMask(
                $numbering->supplierInvoiceMask
                    ?? config('invoicing.supplier_invoice_number_mask', 'DF-{YYYY}-{NNNN}')
            );

            $internalNumber = $this->repository->nextInternalNumber(
                userId: $userId,
                mask: $mask,
                start: $numbering->supplierInvoiceStart,
            );

            $supplierInvoice = $this->repository->create([
                'user_id' => $userId,
                'client_id' => $data->client_id,
                'internal_number' => $internalNumber,
                'supplier_invoice_number' => $data->supplier_invoice_number,
                'variable_symbol' => $data->variable_symbol,
                'status' => 'draft',
                'provenance' => $provenance->value,
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
                'vendor_account_number' => $data->vendor_account_number,
                'vendor_bank_code' => $data->vendor_bank_code,
                'vendor_iban' => $data->vendor_iban,
                'vendor_bic' => $data->vendor_bic,
                'account_source' => $data->hasVendorAccount() ? 'manual' : null,
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
