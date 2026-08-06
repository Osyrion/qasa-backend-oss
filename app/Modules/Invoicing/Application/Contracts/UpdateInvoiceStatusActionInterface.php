<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;
use Throwable;

interface UpdateInvoiceStatusActionInterface
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice, InvoiceStatus $newStatus): Invoice;
}
