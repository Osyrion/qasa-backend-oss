<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Application\DTOs\SendInvoiceEmailData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;
use Throwable;

/**
 * The contract other modules reach through — ModuleBoundariesTest requires
 * cross-module Application-layer dependencies to go via an interface, not a
 * concrete Action class. Used by Automation\AutoSendInvoiceOnIssue (N3 rule 3).
 */
interface SendInvoiceEmailActionInterface
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice, ?SendInvoiceEmailData $data = null): Invoice;
}
