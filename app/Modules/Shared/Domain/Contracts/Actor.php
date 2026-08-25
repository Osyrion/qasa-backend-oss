<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * The authenticated person acting in the current request: which account they
 * belong to, who they are, and what they are allowed to do.
 *
 * A policy needs those halves — which account this is (Account) and what it is
 * allowed to do (Laravel's Authorizable) — and writing the intersection out at
 * every one of the hundred-odd policy methods would say the same thing a
 * hundred times. This is that intersection, named.
 *
 * The distinction from Account itself is worth keeping, and it is not
 * cosmetic: a queue job or a console command handles an *account* without
 * anyone acting, and typing those as an Actor would claim a request context
 * they do not have. It is also where the person lives — see actorId().
 */
interface Actor extends Account, Authorizable
{
    /**
     * This person's own id — never the account's.
     *
     * The two are the same value for an account owner and differ for every
     * team member, which is exactly what makes confusing them expensive: the
     * mistake is invisible on the accounts most code is written against. It
     * cost a 500 on every invoice a team member created through the UI, from
     * an idempotency row written with the person's id into a table whose RLS
     * policy compares against the account's.
     *
     * Use it for what belongs to the *person* — authorship, a notification's
     * recipient, a per-caller key namespace. Anything that belongs to the
     * tenant is accountOwnerId(), and a tenant-scoped `user_id` column is
     * always the latter.
     */
    public function actorId(): string;
}
