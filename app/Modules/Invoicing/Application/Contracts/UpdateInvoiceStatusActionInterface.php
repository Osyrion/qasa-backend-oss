<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

/**
 * Advancing an invoice's status from outside Invoicing.
 *
 * Takes an id and returns nothing — the same shape
 * {@see UpdateSupplierInvoiceStatusActionInterface} and
 * {@see RecordPaymentActionInterface} took, and for the same reason: handing
 * the aggregate out makes the caller name our model for a write it does not
 * read back. Invoicing's own controller keeps the concrete action, which
 * returns the document it renders.
 */
interface UpdateInvoiceStatusActionInterface
{
    /**
     * @throws ModelNotFoundException when the current account does not own it
     * @throws DomainException when the transition is not allowed
     * @throws Throwable
     */
    public function transition(string $invoiceId, InvoiceStatus $newStatus): void;
}
