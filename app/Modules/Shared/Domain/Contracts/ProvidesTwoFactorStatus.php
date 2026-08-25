<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * Whether *this person* carries a confirmed second factor.
 *
 * About the person, not the account — a team member's enrollment says nothing
 * about the owner's. The account-wide requirement is a separate question and
 * a separate contract (Saas's ProvidesAccountSecurityPolicy), because only
 * the SaaS edition has one.
 */
interface ProvidesTwoFactorStatus
{
    public function hasTwoFactorEnabled(): bool;
}
