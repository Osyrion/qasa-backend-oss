<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The single definition of "revenue" and "costs" for the statistics
 * dashboard (see docs/PLAN_STATISTIKY.md). Revenue is invoices + credit
 * notes (proformas and storno excluded — see class doc on the exclusions),
 * counted on the tax-payer basis (subtotal for VAT payers, total otherwise),
 * dated by DUZP with an issued_at fallback. Costs mirror this over
 * supplier_invoices. All monetary figures are returned converted into the
 * user's default currency.
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

        $invoiceYears = Invoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereIn('type', self::REVENUE_TYPES)
            ->whereIn('status', self::REVENUE_STATUSES)
            ->selectRaw('DISTINCT EXTRACT(YEAR FROM COALESCE(taxable_supply_at, issued_at))::int AS year')
            ->pluck('year');

        $supplierYears = SupplierInvoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereIn('status', self::COST_STATUSES)
            ->selectRaw('DISTINCT EXTRACT(YEAR FROM COALESCE(taxable_supply_at, issued_at))::int AS year')
            ->pluck('year');

        return array_values($invoiceYears->merge($supplierYears)
            ->map(fn (mixed $year): int => (int) $year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all());
    }

    /**
     * @return array<string, float>
     */
    private function monthlySeries(User $user, string $from, string $to, bool $revenue): array
    {
        $userId = $user->accountOwnerId();
        $amountColumn = $user->is_vat_payer ? 'subtotal' : 'total';

        /** @var Collection<int, object{month: string, currency: string, converted_czk: string|int|float|null, unconverted: string|int|float|null}> $rows */
        $rows = $revenue
            ? Invoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereIn('type', self::REVENUE_TYPES)
                ->whereIn('status', self::REVENUE_STATUSES)
                ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$from, $to])
                ->selectRaw($amountColumn === 'subtotal' ? self::MONTHLY_SELECT_SUBTOTAL_SNAPSHOT : self::MONTHLY_SELECT_TOTAL_SNAPSHOT)
                ->groupBy('month', 'currency')
                ->get()
            : SupplierInvoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereIn('status', self::COST_STATUSES)
                ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$from, $to])
                ->selectRaw($amountColumn === 'subtotal' ? self::MONTHLY_SELECT_SUBTOTAL_RATE : self::MONTHLY_SELECT_TOTAL_RATE)
                ->groupBy('month', 'currency')
                ->get();

        $czkByMonth = [];

        foreach ($rows as $row) {
            /** @var Currency $currency */
            $currency = $row->currency;
            $convertedCzk = (float) $row->converted_czk;
            $unconverted = (float) $row->unconverted;

            if ($unconverted !== 0.0) {
                $convertedCzk += $unconverted * $this->currencyConverter->fallbackRateToCzk($currency, $userId);
            }

            $czkByMonth[$row->month] = ($czkByMonth[$row->month] ?? 0.0) + $convertedCzk;
        }

        $months = [];
        foreach ($this->monthRange($from, $to) as $month) {
            $czk = $czkByMonth[$month] ?? 0.0;
            $months[$month] = round($this->currencyConverter->czkToDefault($czk, $user), 2);
        }

        return $months;
    }

    private const MONTHLY_SELECT_SUBTOTAL_SNAPSHOT = "
        to_char(COALESCE(taxable_supply_at, issued_at), 'YYYY-MM') AS month,
        currency,
        SUM(CASE WHEN currency = 'CZK' THEN subtotal
                 WHEN exchange_rate_snapshot IS NOT NULL THEN subtotal * exchange_rate_snapshot
                 END) AS converted_czk,
        COALESCE(SUM(subtotal) FILTER (WHERE currency <> 'CZK' AND exchange_rate_snapshot IS NULL), 0) AS unconverted
    ";

    private const MONTHLY_SELECT_TOTAL_SNAPSHOT = "
        to_char(COALESCE(taxable_supply_at, issued_at), 'YYYY-MM') AS month,
        currency,
        SUM(CASE WHEN currency = 'CZK' THEN total
                 WHEN exchange_rate_snapshot IS NOT NULL THEN total * exchange_rate_snapshot
                 END) AS converted_czk,
        COALESCE(SUM(total) FILTER (WHERE currency <> 'CZK' AND exchange_rate_snapshot IS NULL), 0) AS unconverted
    ";

    private const MONTHLY_SELECT_SUBTOTAL_RATE = "
        to_char(COALESCE(taxable_supply_at, issued_at), 'YYYY-MM') AS month,
        currency,
        SUM(CASE WHEN currency = 'CZK' THEN subtotal
                 WHEN exchange_rate IS NOT NULL THEN subtotal * exchange_rate
                 END) AS converted_czk,
        COALESCE(SUM(subtotal) FILTER (WHERE currency <> 'CZK' AND exchange_rate IS NULL), 0) AS unconverted
    ";

    private const MONTHLY_SELECT_TOTAL_RATE = "
        to_char(COALESCE(taxable_supply_at, issued_at), 'YYYY-MM') AS month,
        currency,
        SUM(CASE WHEN currency = 'CZK' THEN total
                 WHEN exchange_rate IS NOT NULL THEN total * exchange_rate
                 END) AS converted_czk,
        COALESCE(SUM(total) FILTER (WHERE currency <> 'CZK' AND exchange_rate IS NULL), 0) AS unconverted
    ";

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
