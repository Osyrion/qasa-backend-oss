<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * What an outgoing e-mail was about, as a short wire value.
 *
 * Lives in Shared rather than in the module that records deliveries, because
 * the mailables that declare it are core (Invoicing) and the recorder is
 * premium (Integrations) — a core mailable naming an Integrations enum would
 * break the edition boundary, and this direction does not.
 *
 * Never an FQCN: the value travels out to the ESP as message metadata and
 * comes back through a webhook, so it has to be stable and safe to expose.
 */
enum EmailDocumentType: string
{
    case Invoice = 'invoice';
    case Quote = 'quote';
    case SupplierInvoice = 'supplier_invoice';
    case SubscriptionInvoice = 'subscription_invoice';
}
