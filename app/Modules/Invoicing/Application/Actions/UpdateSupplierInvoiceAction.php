<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\SupplierInvoiceData;
use App\Modules\Invoicing\Domain\Enums\SupplierVatRegime;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Enums\VatStatus;
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

        $owner = $supplierInvoice->user;
        assert($owner !== null);

        if ($data->vat_regime !== SupplierVatRegime::Domestic && $owner->accountOwner()->vat_status === VatStatus::NonPayer) {
            throw DomainException::because(__('invoicing.supplier_invoice.self_assessment_requires_vat_status'));
        }

        return DB::transaction(function () use ($supplierInvoice, $data): SupplierInvoice {
            $attributes = [
                'client_id' => $data->client_id,
                'supplier_invoice_number' => $data->supplier_invoice_number,
                'variable_symbol' => $data->variable_symbol,
                'vat_regime' => $data->vat_regime->value,
                'issued_at' => $data->issued_at,
                'taxable_supply_at' => $data->taxable_supply_at,
                'due_at' => $data->due_at,
                'received_at' => $data->received_at,
                'currency' => $data->currency->value,
                'exchange_rate' => $data->exchange_rate,
                'note' => $data->note,
                'vendor_account_number' => $data->vendor_account_number,
                'vendor_bank_code' => $data->vendor_bank_code,
                'vendor_iban' => $data->vendor_iban,
                'vendor_bic' => $data->vendor_bic,
            ];

            // A verification is tied to the exact account it checked — any
            // account change resets it and marks the account as manual.
            if ($this->accountChanged($supplierInvoice, $data)) {
                $attributes['account_source'] = $data->hasVendorAccount() ? 'manual' : null;
                $attributes['account_verified_at'] = null;
                $attributes['account_verification_result'] = null;
            }

            $updated = $this->repository->update($supplierInvoice, $attributes);

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

    private function accountChanged(SupplierInvoice $supplierInvoice, SupplierInvoiceData $data): bool
    {
        return $supplierInvoice->vendor_account_number !== $data->vendor_account_number
            || $supplierInvoice->vendor_bank_code !== $data->vendor_bank_code
            || $supplierInvoice->vendor_iban !== $data->vendor_iban
            || $supplierInvoice->vendor_bic !== $data->vendor_bic;
    }
}
