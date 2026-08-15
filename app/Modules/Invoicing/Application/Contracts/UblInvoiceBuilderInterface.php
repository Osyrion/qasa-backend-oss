<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * The UBL 2.1 / EN 16931 rendering of an invoice, reachable from outside the
 * Invoicing module.
 *
 * Exists because the concrete builder lives in Infrastructure and
 * ModuleBoundariesTest forbids another module's Application layer from
 * reaching in there — same reason and same shape as
 * ProcessInboxFileActionInterface. The caller that needs it is the premium
 * Peppol transport: it hands an access point the exact bytes this produces.
 *
 * That is also why the transport contract does *not* live here in reverse.
 * The dependency only ever runs premium → core (the sender reads a core
 * invoice and asks core to render it); core never needs to call the
 * transport, so a no-op default in core would be a binding nothing resolves.
 */
interface UblInvoiceBuilderInterface
{
    /**
     * @throws DomainException when the document type has no UBL equivalent
     *                         (proforma, storno)
     */
    public function build(Invoice $invoice): string;
}
