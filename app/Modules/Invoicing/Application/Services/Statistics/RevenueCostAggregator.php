<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The single definition of "revenue" and "costs" for the statistics
 * dashboard. Revenue is invoices + credit notes (proformas and storno
 * excluded — see class doc on the exclusions), counted on the tax-payer
 * basis (subtotal for VAT payers, total otherwise), dated by DUZP with an
 * issued_at fallback. Costs mirror this over supplier_invoices, plus
 * recorded expenses (full amount, no VAT split — Expense carries none)
 * dated by their own date column. A receipt logged as both an expense and a
 * supplier invoice double-counts — the application does not deduplicate
 * between the two, it's on the user's discipline. All monetary figures are
 * returned converted into the user's default currency.
 *
 * Bucketing and conversion to CZK happen in SQL, one row per month per
 * source. The final conversion into the user's default currency stays in
 * PHP because it rounds per month, and callers depend on that: both
 * revenueBetween() and costsBetween() sum the rounded monthly figures, so
 * collapsing them into a single SQL SUM over the range would shift totals
 * by cents.
 */
final readonly class RevenueCostAggregator
{
    /**
     * credited is included so a credited original plus its negative credit
     * note nets to zero rather than undercounting revenue; cancelled
     * originals are excluded because CreateCorrectiveInvoiceAction
     * auto-cancels them (their negative storno would otherwise double-count
     * the reversal).
     */
    private const REVENUE_TYPES = ['invoice', 'credit_note'];

    private const REVENUE_STATUSES = ['issued', 'sent', 'reminded', 'paid', 'credited'];

    private const COST_STATUSES = ['received', 'booked', 'paid'];

    /**
     * The DUZP-with-fallback date basis every invoice-side figure is dated
     * by. Matches the invoices_user_effective_date_idx expression index.
     */
    private const EFFECTIVE_DATE = 'COALESCE(taxable_supply_at, issued_at)';

    public function __construct(
        private StatisticsCurrencyConverter $currencyConverter,
    ) {}

    /**
     * @return array<string, float> keyed by 'YYYY-MM', zero-filled for every
     *                              calendar month between $from and $to
     */
    public function monthlyRevenue(User $user, string $from, string $to): array
    {
        return $this->monthlySeries($user, $from, $to, revenue: true);
    }

    /**
     * @return array<string, float> keyed by 'YYYY-MM', zero-filled for every
     *                              calendar month between $from and $to
     */
    public function monthlyCosts(User $user, string $from, string $to): array
    {
        return $this->monthlySeries($user, $from, $to, revenue: false);
    }

    public function revenueBetween(User $user, string $from, string $to): float
    {
        return array_sum($this->monthlyRevenue($user, $from, $to));
    }

    public function costsBetween(User $user, string $from, string $to): float
    {
        return array_sum($this->monthlyCosts($user, $from, $to));
    }

    /**
     * All calendar years with recorded revenue or cost activity, newest
     * first.
     *
     * @return list<int>
     */
    public function activityYears(User $user): array
    {
        $userId = $user->accountOwnerId();

        $years = [
            ...$this->distinctYears(
                Invoice::withoutGlobalScope('user')
                    ->where('user_id', $userId)
                    ->whereIn('type', self::REVENUE_TYPES)
                    ->whereIn('status', self::REVENUE_STATUSES),
                self::EFFECTIVE_DATE,
            ),
            ...$this->distinctYears(
                SupplierInvoice::withoutGlobalScope('user')
                    ->where('user_id', $userId)
                    ->whereIn('status', self::COST_STATUSES),
                self::EFFECTIVE_DATE,
            ),
            ...$this->distinctYears(
                Expense::withoutGlobalScope('user')->where('user_id', $userId),
                'date',
            ),
        ];

        $years = array_values(array_unique($years));
        rsort($years);

        return $years;
    }

    /**
     * @return array<string, float>
     */
    private function monthlySeries(User $user, string $from, string $to, bool $revenue): array
    {
        $userId = $user->accountOwnerId();
        $amountColumn = $user->is_vat_payer ? 'subtotal' : 'total';

        if ($revenue) {
            $czkByMonth = $this->czkByMonth(
                Invoice::withoutGlobalScope('user')
                    ->where('user_id', $userId)
                    ->whereIn('type', self::REVENUE_TYPES)
                    ->whereIn('status', self::REVENUE_STATUSES)
                    ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$from, $to]),
                self::EFFECTIVE_DATE,
                $amountColumn,
                'exchange_rate_snapshot',
                $userId,
            );
        } else {
            $czkByMonth = $this->czkByMonth(
                SupplierInvoice::withoutGlobalScope('user')
                    ->where('user_id', $userId)
                    ->whereIn('status', self::COST_STATUSES)
                    ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$from, $to]),
                self::EFFECTIVE_DATE,
                $amountColumn,
                'exchange_rate',
                $userId,
            );

            // Expense carries no frozen exchange rate at all, so a non-CZK
            // row always falls back to the stored/system rate.
            $expenses = $this->czkByMonth(
                Expense::withoutGlobalScope('user')
                    ->where('user_id', $userId)
                    ->whereBetween('date', [$from, $to]),
                'date',
                'amount',
                null,
                $userId,
            );

            foreach ($expenses as $month => $czk) {
                $czkByMonth[$month] = ($czkByMonth[$month] ?? 0.0) + $czk;
            }
        }

        $months = [];
        foreach ($this->monthRange($from, $to) as $month) {
            $czk = $czkByMonth[$month] ?? 0.0;
            $months[$month] = round($this->currencyConverter->czkToDefault($czk, $user), 2);
        }

        return $months;
    }

    /**
     * Sum a source into CZK per 'YYYY-MM' bucket.
     *
     * @param  Builder<Invoice>|Builder<SupplierInvoice>|Builder<Expense>  $query
     * @param  literal-string  $dateExpression
     * @param  literal-string  $amountColumn
     * @param  literal-string|null  $frozenRateColumn
     * @return array<string, float>
     */
    private function czkByMonth(Builder $query, string $dateExpression, string $amountColumn, ?string $frozenRateColumn, string $userId): array
    {
        $czkSum = $this->currencyConverter->czkSum($amountColumn, $frozenRateColumn, $userId);

        return $query
            ->selectRaw(
                "to_char(date_trunc('month', {$dateExpression}), 'YYYY-MM') as month, {$czkSum['sql']} as czk_total",
                $czkSum['bindings'],
            )
            ->groupBy('month')
            ->pluck('czk_total', 'month')
            ->map(fn (mixed $total): float => (float) $total)
            ->all();
    }

    /**
     * @param  Builder<Invoice>|Builder<SupplierInvoice>|Builder<Expense>  $query
     * @param  literal-string  $dateExpression
     * @return list<int>
     */
    private function distinctYears(Builder $query, string $dateExpression): array
    {
        return array_values(
            $query
                ->distinct()
                ->selectRaw("EXTRACT(YEAR FROM {$dateExpression})::int as year")
                ->pluck('year')
                ->map(fn (mixed $year): int => (int) $year)
                ->all()
        );
    }

    /**
     * @return list<string> 'YYYY-MM' labels for every calendar month between
     *                      $from and $to, inclusive
     */
    private function monthRange(string $from, string $to): array
    {
        $cursor = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->startOfMonth();

        $months = [];
        while ($cursor->lessThanOrEqualTo($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return $months;
    }
}
