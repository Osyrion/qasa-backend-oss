<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The canonical JSON body for an invoice — the `#/components/schemas/Invoice`
 * shape, as returned by the invoice endpoints.
 *
 * Same arrangement as Clients' ClientRepresentation and Orders'
 * OrderRepresentation: the shape is the contract, the resource is one
 * implementation of it, which is why this interface lives in Application while
 * what satisfies it lives in Presentation. Keyed by id, because a module that
 * has an invoice model to hand us has already crossed the line this closes.
 */
interface InvoiceRepresentation
{
    /**
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException when the current account has no such invoice
     */
    public function forInvoice(string $invoiceId): array;
}
