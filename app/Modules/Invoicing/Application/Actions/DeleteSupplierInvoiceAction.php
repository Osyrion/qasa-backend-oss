<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Exceptions\DomainException;

readonly class DeleteSupplierInvoiceAction
{
    public function __construct(
        private SupplierInvoiceRepositoryInterface $repository,
    ) {}

    /**
     * @throws DomainException
     */
    public function execute(SupplierInvoice $supplierInvoice): void
    {
        if (! $supplierInvoice->isEditable()) {
            throw DomainException::because(__('invoicing.supplier_invoice.only_draft_editable'));
        }

        $this->repository->delete($supplierInvoice);
    }
}
