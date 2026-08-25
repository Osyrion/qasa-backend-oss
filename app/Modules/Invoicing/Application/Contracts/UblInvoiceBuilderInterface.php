<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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
 *
 * **It takes an id, not the model.** Asking for `Invoice` was the only reason
 * the two Peppol actions had to name it: they loaded the aggregate — and had
 * to know which relations to eager-load for a renderer they cannot see — for
 * no purpose other than handing it straight back to Invoicing. Which columns
 * and relations the rendering needs is the implementation's business, and the
 * implementation lives in the module that owns them.
 */
interface UblInvoiceBuilderInterface
{
    /**
     * @throws ModelNotFoundException when the account cannot see the document
     * @throws DomainException when the document type has no UBL equivalent
     *                         (proforma, storno)
     */
    public function build(string $invoiceId): string;
}
