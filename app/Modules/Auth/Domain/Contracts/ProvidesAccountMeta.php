<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Contracts;

/**
 * Account metadata exposed by UserResource — the core User implements it
 * with single-account defaults; alternative implementations can supply
 * richer values while the API shape stays identical.
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
