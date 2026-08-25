<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * What the account's subscription plan lets it do.
 *
 * The methods are edition hooks, not a plan API: the core (OSS) edition has
 * no plans at all and answers "yes, unlimited" to everything, while the SaaS
 * User model resolves each against the active subscription and any
 * admin-granted entitlement. The contract exists so a module can ask the
 * question without naming SubscriptionPlan — a premium model it must not see
 * — and without naming the edition's User class either.
 */
interface ProvidesPlanEntitlements
{
    /**
     * Whether the plan includes a named feature.
     */
    public function hasFeature(string $feature): bool;

    /**
     * Whether there is room for one more, given the usage the account has
     * (or, for a size-based limit, would have) right now.
     */
    public function withinLimit(string $limitKey, int $currentCount): bool;

    /**
     * The numeric ceiling for a metered limit key, for callers that have to
     * report the remaining quota rather than just enforce it.
     *
     * Three-valued on purpose: -1 is unlimited, 0 is "this account has no
     * plan, so no quota at all", and anything else is the ceiling.
     */
    public function planLimit(string $limitKey): int;

    /**
     * Whether the plan permits taking card payments on invoices. Distinct
     * from hasFeature(): online payments are a dedicated column on the plan,
     * not an entry in its feature list.
     */
    public function allowsOnlinePayments(): bool;
}
