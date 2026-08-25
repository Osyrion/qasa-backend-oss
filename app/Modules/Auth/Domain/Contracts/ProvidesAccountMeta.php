<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Contracts;

/**
 * Account metadata exposed by UserResource — implemented with single-account
 * defaults by the core User and with roles/teams/billing by the SaaS User,
 * so the API shape is identical in both editions.
 */
interface ProvidesAccountMeta
{
    public function roleName(): ?string;

    /**
     * @return list<string>
     */
    public function permissionNames(): array;

    public function isTeamMember(): bool;

    /**
     * Owner info shown to team members; null for account owners.
     *
     * @return array{id: string, full_name: string, email: string}|null
     */
    public function accountOwnerMeta(): ?array;

    /**
     * Whether the plan field should be present in the API response.
     */
    public function exposesPlan(): bool;

    public function planSlug(): ?string;

    /**
     * Card-free trial state, or null when the edition has no trials and when
     * the account has nothing pending or running.
     *
     * Two shapes, told apart by `status`:
     *
     * - `active` — a trial is running; `ends_at` and `days_left` are set.
     * - `pending_verification` — the account is entitled to a trial but has
     *   not verified a phone number yet; `eligible_until` and `days_left`
     *   (days left to verify, not days of trial) are set instead.
     *
     * The discriminator exists because those two states used to be
     * indistinguishable from "no trial at all", which left the UI with
     * nothing to say to an account that was one SMS away from fourteen free
     * days.
     *
     * @return array{status: 'active'|'pending_verification', ends_at: string|null, days_left: int, eligible_until: string|null}|null
     */
    public function trialMeta(): ?array;
}
