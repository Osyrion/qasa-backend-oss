<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Auth\Application\Contracts\DashboardStatsContributor;
use App\Modules\Orders\Domain\Models\Order;

/**
 * Orders' section of the account dashboard — which statuses exist and what
 * makes an order billable are facts about `orders`, not about the dashboard.
 */
final class OrdersDashboardStats implements DashboardStatsContributor
{
    /**
     * @return array<string, mixed>
     */
    public function statsFor(string $ownerId, int $year, int $month): array
    {
        return [
            'orders' => $this->orderStats($ownerId),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function orderStats(string $userId): array
    {
        /** @var array<string, int> $counts */
        $counts = Order::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->selectRaw("
                count(*) as total,
                count(*) filter (where status = 'active') as active,
                count(*) filter (where status = 'completed') as completed,
                count(*) filter (where client_id is not null) as billable
            ")
            ->first()
            ?->toArray() ?? [];

        return [
            'total' => (int) ($counts['total'] ?? 0),
            'active' => (int) ($counts['active'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'billable' => (int) ($counts['billable'] ?? 0),
        ];
    }
}
