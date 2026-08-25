<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceStatus;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

/**
 * Advancing a supplier invoice's status from outside Invoicing.
 *
 * Takes an id and returns nothing: the one caller outside the module is a
 * payment batch marking what it just paid, and handing it the aggregate would
 * make it name our model for a write it does not read back. Invoicing's own
 * controller keeps the concrete action's `execute()`, which returns the model
 * it renders.
 */
interface UpdateSupplierInvoiceStatusActionInterface
{
    /**
     * @throws ModelNotFoundException when the current account does not own it
     * @throws DomainException when the transition is not allowed
     * @throws Throwable
     */
    public function transition(string $supplierInvoiceId, SupplierInvoiceStatus $newStatus, ?string $paidAt = null): void;
}
