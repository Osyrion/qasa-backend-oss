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

            $item->supplier_invoice_id = $supplierInvoice->id;
            $item->status = InvoiceInboxStatus::Imported->value;
            $item->save();

            return $supplierInvoice;
        });
    }
}
