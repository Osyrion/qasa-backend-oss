<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use Illuminate\Support\Carbon;

/**
 * Pure date math for the statistics dashboard — every period is derived from
 * a single `now()` captured at construction so a whole request sees one
 * consistent "today", regardless of how many periods it computes.
 */
final readonly class StatisticsPeriods
{
    private Carbon $now;

    public function __construct(?Carbon $now = null)
    {
        $this->now = ($now ?? Carbon::now())->copy();
    }

    /**
     * Current calendar month, from its start up to today (partial).
     *
     * @return array{from: string, to: string}
     */
    public function thisMonth(): array
    {
        return [
            'from' => $this->now->copy()->startOfMonth()->toDateString(),
            'to' => $this->now->toDateString(),
        ];
    }

    /**
     * Previous calendar month, full range.
     *
     * @return array{from: string, to: string}
     */
    public function lastMonth(): array
    {
        $lastMonth = $this->now->copy()->subMonthNoOverflow();

        return [
            'from' => $lastMonth->copy()->startOfMonth()->toDateString(),
            'to' => $lastMonth->copy()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * Trailing 12-month window ending today (11 full months back + current
     * partial month).
     *
     * @return array{from: string, to: string}
     */
    public function rolling12(): array
    {
        return [
            'from' => $this->now->copy()->subMonthsNoOverflow(11)->startOfMonth()->toDateString(),
            'to' => $this->now->toDateString(),
        ];
    }

    /**
     * The 12-month window immediately preceding rolling12() — used for its
     * year-over-year comparison.
     *
     * @return array{from: string, to: string}
     */
    public function previousRolling12(): array
    {
        return [
            'from' => $this->now->copy()->subMonthsNoOverflow(23)->startOfMonth()->toDateString(),
            'to' => $this->now->copy()->subMonthsNoOverflow(12)->endOfMonth()->toDateString(),
        ];
    }

    /**
     * Year-to-date: from the start of the current year up to today.
     *
     * @return array{from: string, to: string}
     */
    public function ytd(): array
    {
        return [
            'from' => $this->now->copy()->startOfYear()->toDateString(),
            'to' => $this->now->toDateString(),
        ];
    }

    /**
     * Previous year, truncated to the same month/day as today — the correct
     * comparison base for a partial-year YTD figure.
     *
     * @return array{from: string, to: string}
     */
    public function previousYtdToSameDate(): array
    {
        $lastYearToday = $this->now->copy()->subYearNoOverflow();

        return [
            'from' => $lastYearToday->copy()->startOfYear()->toDateString(),
            'to' => $lastYearToday->toDateString(),
        ];
    }

    /**
     * Previous calendar year, full range.
     *
     * @return array{from: string, to: string}
     */
    public function lastYear(): array
    {
        $lastYear = $this->now->copy()->subYearNoOverflow();

        return [
            'from' => $lastYear->copy()->startOfYear()->toDateString(),
            'to' => $lastYear->copy()->endOfYear()->toDateString(),
        ];
    }

    public function today(): Carbon
    {
        return $this->now->copy();
    }
}
