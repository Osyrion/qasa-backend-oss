<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\SupplierInvoiceData;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class ConvertInboxItemAction
{
    public function __construct(
        private CreateSupplierInvoiceAction $createSupplierInvoiceAction,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(InvoiceInboxItem $item, SupplierInvoiceData $data, User $user): SupplierInvoice
    {
        if (! $item->statusEnum()->canConvert()) {
            throw DomainException::because(__('invoicing.inbox.already_processed'));
        }

        return DB::transaction(function () use ($item, $data, $user): SupplierInvoice {
            $supplierInvoice = $this->createSupplierInvoiceAction->execute($data, $user);

            // An account carried over unchanged from the OCR suggestions is
            // ocr-sourced; anything the user retyped stays manual.
            if ($supplierInvoice->hasPaymentAccount() && $this->accountMatchesSuggestions($supplierInvoice, $item)) {
                $supplierInvoice->account_source = 'ocr';
                $supplierInvoice->save();
            }

            $item->supplier_invoice_id = $supplierInvoice->id;
            $item->status = InvoiceInboxStatus::Imported->value;
            $item->save();

            return $supplierInvoice;
        });
    }

    private function accountMatchesSuggestions(SupplierInvoice $supplierInvoice, InvoiceInboxItem $item): bool
    {
        $suggestions = $item->suggestions ?? [];

        $ibanMatches = $supplierInvoice->vendor_iban !== null
            && $supplierInvoice->vendor_iban === ($suggestions['iban'] ?? null);

        $domesticMatches = $supplierInvoice->hasDomesticVendorAccount()
            && $supplierInvoice->vendor_account_number === ($suggestions['account_number'] ?? null)
            && $supplierInvoice->vendor_bank_code === ($suggestions['bank_code'] ?? null);

        return $ibanMatches || $domesticMatches;
    }
}
