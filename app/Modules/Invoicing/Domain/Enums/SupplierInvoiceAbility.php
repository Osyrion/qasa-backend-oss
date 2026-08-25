<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

/**
 * What another module can ask permission for on a supplier invoice.
 *
 * One case, because one is asked: Banking checks the vendor's account against
 * the CZ VAT payer register and writes the result onto our document. An enum
 * rather than a string so the published surface is a list somebody has to
 * extend on purpose — same arrangement as {@see InvoiceAbility}.
 */
enum SupplierInvoiceAbility: string
{
    case VerifyAccount = 'verifyAccount';
}
