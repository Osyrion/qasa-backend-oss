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
     * Card-free trial state, or null when the edition has no trials or the
     * account is not on one.
     *
     * @return array{ends_at: string, days_left: int}|null
     */
    public function trialMeta(): ?array;
}
