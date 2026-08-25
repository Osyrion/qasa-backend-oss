<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * Who may act for an account.
 *
 * "The account" is one row in the core edition and a row plus its team members
 * in the SaaS one, and the difference is exactly the kind of thing a caller
 * should not have to know: the inbound-email allowlist asks whether a sender
 * belongs to the account, not whether the product has teams.
 *
 * The core edition binds an owner-only implementation; Saas overrides it with
 * one that includes the members. `owner_id` is a Saas column, so the core
 * answer is not a degraded version of the SaaS one — it is the whole truth
 * there.
 */
interface AccountMemberDirectory
{
    /**
     * Lowercased e-mail addresses of everyone who may act for this account,
     * the owner included. Empty when the account is unknown or the current
     * connection may not see it.
     *
     * @return list<string>
     */
    public function emails(string $accountId): array;
}
