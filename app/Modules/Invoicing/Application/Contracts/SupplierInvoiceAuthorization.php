<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceAbility;
use App\Modules\Shared\Domain\Contracts\Actor;

/**
 * May this person do this to this supplier invoice?
 *
 * The sibling of {@see InvoiceAuthorization} and it exists for the same
 * reason: the Gate finds a policy by model class and route-model binding
 * resolves one from the URL, so a module that must not name `SupplierInvoice`
 * can use neither. The implementation delegates to SupplierInvoicePolicy —
 * nothing here restates the rule.
 *
 * Answering false does not distinguish "not allowed" from "not there". A
 * caller that needs to 404 first asks {@see SupplierInvoicePayments}, whose
 * account scope makes a foreign document invisible, and only then asks this.
 */
interface SupplierInvoiceAuthorization
{
    public function allows(Actor $actor, SupplierInvoiceAbility $ability, string $supplierInvoiceId): bool;
}
