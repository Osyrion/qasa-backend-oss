<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Loading an account by id, for code that runs outside a request.
 *
 * Console commands, queue jobs and webhooks all reach for the same line —
 * `User::find($accountId)` — and every one of them names the auth model to do
 * it. That is both a boundary violation and the edition trap: the SaaS
 * edition swaps the class through `auth.providers.users.model`, so a literal
 * `User::find()` hands back a *core* instance whose plan and team methods do
 * not exist. Resolving the configured model happens once, here.
 *
 * Not a repository for the account aggregate: there is deliberately no save,
 * no create and no query builder. It answers one question — "the account with
 * this id" — and what it returns is FullAccount, the same type a
 * tenant-scoped record's `user` relation carries.
 *
 * It does not bind the tenant, and must not: RLS applies to this read like
 * any other, so the caller binds first (TenantContext::forEachAccount() does
 * exactly that) and a lookup for an account the connection is not bound to
 * correctly finds nothing.
 */
interface AccountLocator
{
    /**
     * The account with this id, or null when there is none the current
     * connection may see.
     */
    public function find(string $accountId): (FullAccount&Model)|null;
}
