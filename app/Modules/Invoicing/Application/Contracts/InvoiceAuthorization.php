<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Enums\InvoiceAbility;
use App\Modules\Shared\Domain\Contracts\Actor;

/**
 * May this person do this to this invoice?
 *
 * Laravel's two idiomatic answers both key on the concrete class — the Gate
 * finds a policy by model class, and route-model binding resolves one from the
 * URL — so a module that must not name `Invoice` cannot use either, and no
 * value object fixes that: `InvoiceSummary` is not what the Gate looks up.
 * This is the third answer, and it keeps the rule where it belongs.
 *
 * **The implementation delegates to the policy.** Nothing here restates what
 * `InvoicePolicy` says; there is still exactly one place where "may I remind"
 * is decided, and it is the same place Invoicing's own controllers reach.
 *
 * Answering false does not distinguish "not allowed" from "not there" — that
 * is on purpose, because leaking the difference is itself a disclosure. A
 * caller that needs to 404 first asks InvoiceLookup, whose account scope makes
 * a foreign document invisible, and only then asks this. Getting that order
 * wrong turns today's 404 into a 403 that confirms the document exists.
 */
interface InvoiceAuthorization
{
    public function allows(Actor $actor, InvoiceAbility $ability, string $invoiceId): bool;

    /**
     * The class-level ability, which takes no document: may this person draft
     * an invoice at all?
     */
    public function allowsCreate(Actor $actor): bool;
}
