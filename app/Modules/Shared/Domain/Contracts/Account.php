<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * The tenant a record belongs to.
 *
 * Every module needs this concept and none of them owns it: `user_id` on a
 * tenant-scoped table is the account key, not a foreign key into Auth's
 * aggregate (see HasUserScope, TenantContext and the RLS policies —
 * docs/app/APLIKACIA.md kapitola 4). Naming the concrete User model to express
 * it is what made twenty modules depend on Auth, and — because the SaaS
 * edition swaps that class through the auth config — what made those
 * dependencies point at the *wrong* class half the time.
 *
 * Deliberately tiny. This is identity, not the account's whole surface: what a
 * module needs to know is which account it is dealing with. Business details
 * come from ProvidesSupplierProfile, the UI language from Laravel's own
 * HasLocalePreference, and authorization from Authorizable — all of which the
 * edition's User model also implements.
 */
interface Account
{
    /**
     * The account that owns this record — for a team member, the owner's id
     * rather than their own.
     */
    public function accountOwnerId(): string;

    /**
     * Whether this is the account owner rather than one of its team members.
     *
     * The same fact as accountOwnerId() asked as a predicate, and the one
     * every owner-only endpoint needs before it will touch account-wide
     * settings. Identity, not authorization: what the owner may *do* is a
     * policy question and belongs on Actor.
     *
     * The core (OSS) edition is single-account, so its User answers true
     * unconditionally; the SaaS edition answers against owner_id.
     */
    public function isOwner(): bool;
}
