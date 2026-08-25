<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * Whether the account may be used at all.
 *
 * Separate from Account (which is identity and nothing else) and from
 * authorization: a suspended account's credentials are valid and its
 * permissions unchanged — the platform has simply switched it off. That is
 * why the check lives in the authentication middleware rather than in a
 * policy, and why it has to be answerable without naming the auth model:
 * Shared owns that middleware.
 *
 * The account's state, never the person's — a team member of a suspended
 * account is suspended too. That is also why it extends Account rather than
 * standing alone: the flag lives on the owner's row, so "is this switched
 * off" and "which account is this" are one question asked twice, and a caller
 * that has to narrow to both ends up writing an instanceof that the core
 * edition can prove is always true.
 */
interface ProvidesAccountStatus extends Account
{
    public function isSuspended(): bool;

    /**
     * Why, so support has something to point at. Null when not suspended.
     */
    public function suspensionReason(): ?string;
}
