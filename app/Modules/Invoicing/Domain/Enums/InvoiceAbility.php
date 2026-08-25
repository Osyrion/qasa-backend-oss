<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

/**
 * What another module can ask permission for on an invoice.
 *
 * The cases are exactly the InvoicePolicy methods something outside Invoicing
 * authorises against today — an enum rather than a string so that a typo is a
 * compile-time problem and so that the published surface is a list somebody
 * has to extend on purpose. Invoicing's own controllers keep calling
 * `$this->authorize('remind', $invoice)`: they hold the model legitimately,
 * and Laravel's own mechanism is the better one when you can use it.
 */
enum InvoiceAbility: string
{
    case View = 'view';
    case Email = 'email';
    case Remind = 'remind';
    case RecordPayment = 'recordPayment';
}
