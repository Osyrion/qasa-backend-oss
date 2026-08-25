<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\InvoiceAuthorization;
use App\Modules\Invoicing\Domain\Enums\InvoiceAbility;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Domain\Contracts\Actor;
use Illuminate\Support\Facades\Gate;

/**
 * Answers the question by asking InvoicePolicy, so the rule has one home.
 *
 * `Gate::forUser()` rather than the ambient user: the caller passes the actor
 * explicitly, and a check that silently used whoever happened to be
 * authenticated would be a different — and worse — contract.
 */
final readonly class GateInvoiceAuthorization implements InvoiceAuthorization
{
    public function allows(Actor $actor, InvoiceAbility $ability, string $invoiceId): bool
    {
        $invoice = Invoice::query()->find($invoiceId);

        if (! $invoice instanceof Invoice) {
            return false;
        }

        return Gate::forUser($actor)->allows($ability->value, $invoice);
    }

    public function allowsCreate(Actor $actor): bool
    {
        return Gate::forUser($actor)->allows('create', Invoice::class);
    }
}
