<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;

/**
 * Monthly usage metering for plan-gated, per-call features (currently just
 * AI invoice extraction). The OSS core binds an always-available null
 * object (UnlimitedUsageQuota); the SaaS edition overrides it with a real
 * subscription_usages-backed implementation in the Saas module.
 */
interface UsageQuotaInterface
{
    /**
     * Remaining calls in the current period, or PHP_INT_MAX when unlimited.
     */
    public function remaining(Account&ProvidesPlanEntitlements $owner, string $feature): int;

    /**
     * Atomically consumes one unit if the period's limit isn't already hit.
     * Returns false (and consumes nothing) when the quota is exhausted.
     */
    public function consume(Account&ProvidesPlanEntitlements $owner, string $feature, int $amount = 1): bool;

    /**
     * Returns a unit previously consumed — used when a consumed call ends
     * up failing (e.g. the LLM call itself errors) so the owner isn't
     * charged for a suggestion they never received.
     */
    public function refund(Account&ProvidesPlanEntitlements $owner, string $feature, int $amount = 1): void;
}
