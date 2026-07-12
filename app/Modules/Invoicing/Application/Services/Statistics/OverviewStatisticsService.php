<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * KPI cards, period comparison, and profit charts — the landing view of the
 * BI dashboard. Everything is converted into the user's default currency;
 * see RevenueCostAggregator for the revenue/cost definition and
 * StatisticsPeriods for the period date math.
 */
final readonly class OverviewStatisticsService
{
    public function __construct(
        private RevenueCostAggregator $aggregator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getStatistics(User $user): array
    {
        $ownerId = $user->accountOwnerId();
        $currency = $user->default_currency->value;
        $today = Carbon::now()->toDateString();

        return Cache::remember(
            "stats:overview:{$ownerId}:{$currency}:{$today}",
            300,
            fn (): array => $this->compute($user),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(User $user): array
    {
        $periods = new StatisticsPeriods;

        $thisMonthRange = $periods->thisMonth();
        $lastMonthRange = $periods->lastMonth();
        $rolling12Range = $periods->rolling12();
        $prevRolling12Range = $periods->previousRolling12();
        $ytdRange = $periods->ytd();
        $prevYtdRange = $periods->previousYtdToSameDate();
        $lastYearRange = $periods->lastYear();

        $thisMonth = $this->periodTotals($user, $thisMonthRange);
        $lastMonth = $this->periodTotals($user, $lastMonthRange);
        $rolling12 = $this->periodTotals($user, $rolling12Range);
        $prevRolling12 = $this->periodTotals($user, $prevRolling12Range);
        $ytd = $this->periodTotals($user, $ytdRange);
        $prevYtd = $this->periodTotals($user, $prevYtdRange);
        $lastYear = $this->periodTotals($user, $lastYearRange);

        [$monthlyTrend, $profitChart] = $this->charts($user, $periods, $prevYtd);

        return [
            'currency' => $user->default_currency->value,
            'kpi' => [
                'revenue' => $this->kpiBlock($thisMonth, $lastMonth, $rolling12, $prevRolling12, $ytd, $prevYtd, $thisMonthRange, $rolling12Range, $ytdRange, 'revenue'),
                'costs' => $this->kpiBlock($thisMonth, $lastMonth, $rolling12, $prevRolling12, $ytd, $prevYtd, $thisMonthRange, $rolling12Range, $ytdRange, 'costs'),
                'profit' => [
                    ...$this->kpiBlock($thisMonth, $lastMonth, $rolling12, $prevRolling12, $ytd, $prevYtd, $thisMonthRange, $rolling12Range, $ytdRange, 'profit'),
                    'ytd_margin_percent' => $this->marginPercent($ytd['profit'], $ytd['revenue']),
                ],
            ],
            'comparison' => [
                $this->comparisonRow('this_month', $thisMonthRange, $thisMonth),
                $this->comparisonRow('last_month', $lastMonthRange, $lastMonth),
                $this->comparisonRow('rolling_12m', $rolling12Range, $rolling12),
                $this->comparisonRow('ytd', $ytdRange, $ytd),
                $this->comparisonRow('last_year', $lastYearRange, $lastYear),
            ],
            'monthly_trend' => $monthlyTrend,
            'profit_chart' => $profitChart,
        ];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array{revenue: float, costs: float, profit: float}
     */
    private function periodTotals(User $user, array $range): array
    {
        $revenue = $this->aggregator->revenueBetween($user, $range['from'], $range['to']);
        $costs = $this->aggregator->costsBetween($user, $range['from'], $range['to']);

        return [
            'revenue' => $revenue,
            'costs' => $costs,
            'profit' => round($revenue - $costs, 2),
        ];
    }

    /**
     * @param  array{revenue: float, costs: float, profit: float}  $thisMonth
     * @param  array{revenue: float, costs: float, profit: float}  $lastMonth
     * @param  array{revenue: float, costs: float, profit: float}  $rolling12
     * @param  array{revenue: float, costs: float, profit: float}  $prevRolling12
     * @param  array{revenue: float, costs: float, profit: float}  $ytd
     * @param  array{revenue: float, costs: float, profit: float}  $prevYtd
     * @param  array{from: string, to: string}  $thisMonthRange
     * @param  array{from: string, to: string}  $rolling12Range
     * @param  array{from: string, to: string}  $ytdRange
     * @return array<string, mixed>
     */
    private function kpiBlock(
        array $thisMonth,
        array $lastMonth,
        array $rolling12,
        array $prevRolling12,
        array $ytd,
        array $prevYtd,
        array $thisMonthRange,
        array $rolling12Range,
        array $ytdRange,
        string $metric,
    ): array {
        return [
            'this_month' => [
                'value' => $thisMonth[$metric],
                'date_from' => $thisMonthRange['from'],
                'date_to' => $thisMonthRange['to'],
            ],
            'trend_vs_last_month_percent' => $this->percentChange($thisMonth[$metric], $lastMonth[$metric]),
            'rolling_12m' => [
                'value' => $rolling12[$metric],
                'yoy_percent' => $this->percentChange($rolling12[$metric], $prevRolling12[$metric]),
                'date_from' => $rolling12Range['from'],
                'date_to' => $rolling12Range['to'],
            ],
            'ytd' => [
                'value' => $ytd[$metric],
                'yoy_percent' => $this->percentChange($ytd[$metric], $prevYtd[$metric]),
                'date_from' => $ytdRange['from'],
                'date_to' => $ytdRange['to'],
            ],
        ];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @param  array{revenue: float, costs: float, profit: float}  $totals
     * @return array<string, mixed>
     */
    private function comparisonRow(string $period, array $range, array $totals): array
    {
        return [
            'period' => $period,
            'date_from' => $range['from'],
            'date_to' => $range['to'],
            'revenue' => $totals['revenue'],
            'costs' => $totals['costs'],
            'profit' => $totals['profit'],
            'margin_percent' => $this->marginPercent($totals['profit'], $totals['revenue']),
        ];
    }

    /**
     * @param  array{revenue: float, costs: float, profit: float}  $prevYtd
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function charts(User $user, StatisticsPeriods $periods, array $prevYtd): array
    {
        $today = $periods->today();
        $start = $today->copy()->subMonthsNoOverflow(23)->startOfMonth()->toDateString();

        $revenueMap = $this->aggregator->monthlyRevenue($user, $start, $today->toDateString());
        $costsMap = $this->aggregator->monthlyCosts($user, $start, $today->toDateString());

        $profitMap = [];
        foreach ($revenueMap as $month => $revenue) {
            $profitMap[$month] = round($revenue - ($costsMap[$month] ?? 0.0), 2);
        }

        $last12Months = array_slice(array_keys($revenueMap), -12, 12);

        $monthlyTrend = array_map(fn (string $month): array => [
            'month' => $month,
            'revenue' => $revenueMap[$month],
            'costs' => $costsMap[$month] ?? 0.0,
            'profit' => $profitMap[$month],
        ], $last12Months);

        $profitChartMonthly = array_map(fn (string $month): array => [
            'month' => $month,
            'profit' => $profitMap[$month],
            'profit_previous_year' => $profitMap[$this->shiftMonth($month, -12)] ?? 0.0,
        ], $last12Months);

        $cumulativeYtd = $this->cumulativeYtd($today, $profitMap, $prevYtd['profit']);

        return [
            $monthlyTrend,
            [
                'monthly' => $profitChartMonthly,
                'cumulative_ytd' => $cumulativeYtd,
            ],
        ];
    }

    /**
     * @param  array<string, float>  $profitMap
     * @return list<array<string, mixed>>
     */
    private function cumulativeYtd(Carbon $today, array $profitMap, float $preciseCurrentPreviousYtd): array
    {
        $year = (int) $today->format('Y');
        $monthNum = (int) $today->format('n');

        $months = [];
        for ($m = 1; $m <= $monthNum; $m++) {
            $months[] = sprintf('%04d-%02d', $year, $m);
        }

        $lastIndex = count($months) - 1;
        $runningCurrent = 0.0;
        $runningPrevious = 0.0;
        $rows = [];

        foreach ($months as $i => $month) {
            $runningCurrent += $profitMap[$month] ?? 0.0;

            if ($i === $lastIndex) {
                // Previous year's matching month is only partially elapsed
                // as of "today" — use the precise day-level total instead of
                // the full-month figure from the monthly map.
                $runningPrevious = $preciseCurrentPreviousYtd;
            } else {
                $runningPrevious += $profitMap[$this->shiftMonth($month, -12)] ?? 0.0;
            }

            $rows[] = [
                'month' => $month,
                'current' => round($runningCurrent, 2),
                'previous_year' => round($runningPrevious, 2),
            ];
        }

        return $rows;
    }

    private function shiftMonth(string $month, int $months): string
    {
        return Carbon::parse("{$month}-01")->addMonths($months)->format('Y-m');
    }

    private function percentChange(float $current, float $previous): ?float
    {
        if ($previous === 0.0) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    private function marginPercent(float $profit, float $revenue): ?float
    {
        if ($revenue === 0.0) {
            return null;
        }

        return round(($profit / $revenue) * 100, 1);
    }
}
