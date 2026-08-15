<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Contracts;

use Carbon\CarbonImmutable;

/**
 * Lets a module clear request metadata (IP address, user agent, anything else
 * derived from them) off its own tables without Shared knowing the table
 * exists. Implementations are tagged 'privacy.purge' in their own service
 * provider — the same shape as AccountExportContributor and
 * DashboardStatsContributor.
 *
 * Each implementation owns its retention window rather than being handed one:
 * the admin audit deliberately keeps metadata far longer than a tenant's
 * session does, and a single figure passed down would flatten that difference
 * into whichever value the core happened to pick.
 */
interface RequestMetadataPurger
{
    /**
     * @return int Number of rows whose metadata was cleared
     */
    public function purgeAsOf(CarbonImmutable $now): int;
}
