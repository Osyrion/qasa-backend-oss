<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Modules\Auth\Application\Contracts\DashboardStatsContributor;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Application\Contracts\ClientDirectory;

class DashboardService
{
    /**
     * @param  iterable<DashboardStatsContributor>  $contributors  Sections owned by other modules.
     */
    public function __construct(
        private readonly ClientDirectory $clients,
        private readonly iterable $contributors = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getStats(User $user): array
    {
        $userId = $user->accountOwnerId();
        $year = now()->year;
        $month = now()->month;

        // Only what Auth owns. Clients answers through its directory
        // contract; orders, invoices and the income trend arrive as
        // contributors, so the module that owns a table is the one that knows
        // what a statistic over it means.
        $stats = [
            'clients' => $this->clientStats($userId),
        ];

        foreach ($this->contributors as $contributor) {
            $stats = [...$stats, ...$contributor->statsFor($userId, $year, $month)];
        }

        return $stats;
    }

    /**
     * @return array<string, int>
     */
    private function clientStats(string $userId): array
    {
        return [
            'total' => $this->clients->countForAccount($userId),
        ];
    }
}
