<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceStatus;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Exceptions\DomainException;
use Throwable;

interface UpdateSupplierInvoiceStatusActionInterface
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(SupplierInvoice $supplierInvoice, SupplierInvoiceStatus $newStatus, ?string $paidAt = null): SupplierInvoice;
}
