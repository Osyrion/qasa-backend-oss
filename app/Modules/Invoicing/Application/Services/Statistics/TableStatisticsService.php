<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Support\Carbon;

/**
 * Year-by-year and month-by-month revenue/cost/profit tables for the BI
 * dashboard's drill-down views.
 */
final readonly class TableStatisticsService
{
    /**
     * @var list<string>
     */
    private const ASSUMPTIONS = [
        'Náklady zahŕňajú prijaté faktúry aj evidované výdavky (v plnej sume, bez rozpadu DPH) — pri zaevidovaní tej istej položky oboma spôsobmi (výdavok aj prijatá faktúra) sa náklad započíta dvakrát; aplikácia duplicitu nekontroluje, ide o disciplínu používateľa.',
    ];

    public function __construct(
        private RevenueCostAggregator $aggregator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getStatistics(User $user, ?int $year): array
    {
        $year ??= (int) Carbon::now()->year;

        return [
            'currency' => $user->default_currency->value,
            'by_year' => $this->byYear($user),
            'by_month' => $this->byMonth($user, $year),
            'assumptions' => self::ASSUMPTIONS,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byYear(User $user): array
    {
        $years = $this->aggregator->activityYears($user);

        return array_map(function (int $year) use ($user): array {
            $from = sprintf('%04d-01-01', $year);
            $to = sprintf('%04d-12-31', $year);

            $revenue = $this->aggregator->revenueBetween($user, $from, $to);
            $costs = $this->aggregator->costsBetween($user, $from, $to);
            $profit = round($revenue - $costs, 2);

            return [
                'year' => $year,
                'date_from' => $from,
                'date_to' => $to,
                'revenue' => $revenue,
                'costs' => $costs,
                'profit' => $profit,
                'margin_percent' => $this->marginPercent($profit, $revenue),
            ];
        }, $years);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byMonth(User $user, int $year): array
    {
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);

        $revenueMap = $this->aggregator->monthlyRevenue($user, $from, $to);
        $costsMap = $this->aggregator->monthlyCosts($user, $from, $to);

        $rows = [];

        foreach ($revenueMap as $month => $revenue) {
            $costs = $costsMap[$month] ?? 0.0;
            $profit = round($revenue - $costs, 2);
            $start = Carbon::parse("{$month}-01")->startOfMonth();

            $rows[] = [
                'month' => $month,
                'date_from' => $start->toDateString(),
                'date_to' => $start->copy()->endOfMonth()->toDateString(),
                'revenue' => $revenue,
                'costs' => $costs,
                'profit' => $profit,
                'margin_percent' => $this->marginPercent($profit, $revenue),
            ];
        }

        return $rows;
    }

    private function marginPercent(float $profit, float $revenue): ?float
    {
        if ($revenue === 0.0) {
            return null;
        }

        return round(($profit / $revenue) * 100, 1);
    }
}
