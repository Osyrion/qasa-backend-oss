<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Application\DTOs\InvoiceItemData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Shared\Exceptions\DomainException;
use Throwable;

interface AddInvoiceItemActionInterface
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice, InvoiceItemData $data): InvoiceItem;
}
