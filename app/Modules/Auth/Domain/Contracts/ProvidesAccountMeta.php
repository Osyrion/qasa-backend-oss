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
}
