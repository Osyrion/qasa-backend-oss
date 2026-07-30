<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Contracts;

/**
 * Lets a module add its own sections to the dashboard payload without Auth
 * knowing it exists. Implementations are tagged 'dashboard.stats' in their
 * own service provider; the OSS edition simply has no contributors, so those
 * keys are absent from the response.
 */
interface DashboardStatsContributor
{
    /**
     * Extra top-level dashboard sections, keyed by section name.
     *
     * @return array<string, mixed>
     */
    public function statsFor(string $ownerId, int $year, int $month): array;
}
