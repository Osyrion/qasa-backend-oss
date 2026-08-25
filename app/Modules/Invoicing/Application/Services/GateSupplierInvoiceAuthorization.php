<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceAuthorization;
use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceAbility;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Domain\Contracts\Actor;
use Illuminate\Support\Facades\Gate;

/**
 * Answers by asking SupplierInvoicePolicy, so the rule has one home.
 *
 * `Gate::forUser()` rather than the ambient user: the caller passes the actor
 * explicitly, and a check that silently used whoever happened to be
 * authenticated would be a different — and worse — contract.
 */
final readonly class GateSupplierInvoiceAuthorization implements SupplierInvoiceAuthorization
{
    public function allows(Actor $actor, SupplierInvoiceAbility $ability, string $supplierInvoiceId): bool
    {
        $supplierInvoice = SupplierInvoice::query()->find($supplierInvoiceId);

        if (! $supplierInvoice instanceof SupplierInvoice) {
            return false;
        }

        return Gate::forUser($actor)->allows($ability->value, $supplierInvoice);
    }
}
