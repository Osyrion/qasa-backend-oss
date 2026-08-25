<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

/**
 * The three things Invoicing can do without being asked, and therefore the
 * three the automation rate is measured over.
 *
 * Each has its own notion of "automated": a supplier invoice or a payment
 * carries a provenance other than manual, an invoice send was triggered by a
 * schedule rather than a person.
 */
enum AutomationKind: string
{
    case SupplierInvoices = 'supplier_invoices';
    case Payments = 'payments';
    case Sends = 'sends';
}
