<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use App\Modules\Clients\Application\Contracts\ClientDirectory;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Top clients/suppliers and churn risk. Rankings stay in each invoice's
 * native currency (per the dashboard's hybrid currency policy — only
 * KPI/trend/health figures are converted to the user's default currency);
 * only churn's lifetime_revenue is converted, since it aggregates a client's
 * entire history across potentially several currencies into one figure.
 */
final readonly class PartnerStatisticsService
{
    private const REVENUE_TYPES = ['invoice', 'credit_note'];

    private const REVENUE_STATUSES = ['issued', 'sent', 'reminded', 'paid', 'credited'];

    private const COST_STATUSES = ['received', 'booked', 'paid'];

    private const CHURN_DAYS = 60;

    public function __construct(
        private StatisticsCurrencyConverter $currencyConverter,
        private ClientDirectory $clients,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getStatistics(Account&ProvidesSupplierProfile $user, int $limit): array
    {
        $periods = new StatisticsPeriods;
        $rolling12 = $periods->rolling12();

        return [
            'top_clients' => $this->topPartners($user, $rolling12, $limit, revenue: true),
            'top_suppliers' => $this->topPartners($user, $rolling12, $limit, revenue: false),
            'churn_risk' => $this->churnRisk($user, $periods),
        ];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array<string, list<array<string, mixed>>>
     */
    private function topPartners(Account&ProvidesSupplierProfile $user, array $range, int $limit, bool $revenue): array
    {
        $userId = $user->accountOwnerId();
        $amountColumn = $user->supplierProfile()->vatStatus->isVatPayer() ? 'subtotal' : 'total';

        $grouped = ($revenue
            ? Invoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereIn('type', self::REVENUE_TYPES)
                ->whereIn('status', self::REVENUE_STATUSES)
            : SupplierInvoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereIn('status', self::COST_STATUSES))
            ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$range['from'], $range['to']])
            ->whereNotNull('client_id')
            // ROW_NUMBER, not RANK: array_slice() returned exactly $limit
            // rows, whereas RANK would return every partner tied at the
            // cut-off. client_id breaks ties so the order is at least
            // reproducible. The windowed total is the denominator for
            // percent_share and covers every partner in the currency, not
            // just the ones that survive the limit.
            ->selectRaw("client_id, currency, SUM({$amountColumn}) as amount,
                SUM(SUM({$amountColumn})) OVER (PARTITION BY currency) as currency_total,
                ROW_NUMBER() OVER (PARTITION BY currency ORDER BY SUM({$amountColumn}) DESC, client_id) as rn")
            ->groupBy('client_id', 'currency');

        $rows = DB::query()
            ->fromSub($grouped, 'ranked')
            ->where('rn', '<=', $limit)
            ->orderBy('currency')
            ->orderBy('rn')
            ->get();

        $clients = $this->clients->profilesIncludingDeleted($rows->pluck('client_id')->unique()->values());

        $result = [];
        foreach ($rows as $row) {
            $amount = (float) $row->amount;
            $total = (float) $row->currency_total;

            $result[(string) $row->currency][] = [
                'client_id' => $row->client_id,
                'name' => $clients[$row->client_id]->name ?? null,
                'amount' => round($amount, 2),
                'percent_share' => $total > 0.0 ? round($amount / $total * 100, 1) : null,
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function churnRisk(Account&ProvidesSupplierProfile $user, StatisticsPeriods $periods): array
    {
        $userId = $user->accountOwnerId();
        $cutoff = $periods->today()->copy()->subDays(self::CHURN_DAYS)->toDateString();

        /** @var Collection<int, object{client_id: string, last_invoice_at: string}> $lastInvoiceRows */
        $lastInvoiceRows = Invoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereIn('type', self::REVENUE_TYPES)
            ->whereIn('status', self::REVENUE_STATUSES)
            ->whereNotNull('client_id')
            // Only partners flagged as customers can churn. As an EXISTS this
            // rides along with the grouping instead of costing a second round
            // trip plus an in_array() over the result. deleted_at is checked
            // explicitly because the subquery bypasses the SoftDeletes scope.
            ->whereExists(fn (QueryBuilder $clients) => $clients
                ->from('clients')
                ->whereColumn('clients.id', 'invoices.client_id')
                ->where('clients.user_id', $userId)
                ->where('clients.is_customer', true)
                ->whereNull('clients.deleted_at'))
            ->selectRaw('client_id, MAX(COALESCE(taxable_supply_at, issued_at)) as last_invoice_at')
            ->groupBy('client_id')
            ->havingRaw('MAX(COALESCE(taxable_supply_at, issued_at)) <= ?', [$cutoff])
            // Oldest last invoice first — the same order the trailing
            // sortByDesc('days_since_last_invoice') produced, with client_id
            // making ties reproducible.
            ->orderByRaw('MAX(COALESCE(taxable_supply_at, issued_at)) ASC, client_id')
            ->get();

        if ($lastInvoiceRows->isEmpty()) {
            return [];
        }

        $lifetimeCzkByClient = $this->lifetimeRevenueInCzk($user, array_values($lastInvoiceRows->pluck('client_id')->all()));

        $clients = $this->clients->profilesIncludingDeleted($lastInvoiceRows->pluck('client_id'));

        $today = $periods->today();

        return array_values($lastInvoiceRows
            ->map(function (object $row) use ($clients, $lifetimeCzkByClient, $user, $today): array {
                $lastInvoiceAt = Carbon::parse($row->last_invoice_at);
                $lifetimeCzk = $lifetimeCzkByClient[$row->client_id] ?? 0.0;

                return [
                    'client_id' => $row->client_id,
                    'name' => $clients[$row->client_id]->name ?? null,
                    'last_invoice_at' => $lastInvoiceAt->toDateString(),
                    'days_since_last_invoice' => (int) $lastInvoiceAt->diffInDays($today),
                    'lifetime_revenue' => round($this->currencyConverter->czkToDefault($lifetimeCzk, $user), 2),
                    'currency' => $user->supplierProfile()->defaultCurrency->value,
                ];
            })
            ->values()
            ->all());
    }

    /**
     * @param  list<string>  $clientIds
     * @return array<string, float>
     */
    private function lifetimeRevenueInCzk(Account&ProvidesSupplierProfile $user, array $clientIds): array
    {
        $userId = $user->accountOwnerId();
        $czkSum = $this->currencyConverter->czkSum(
            $user->supplierProfile()->vatStatus->isVatPayer() ? 'subtotal' : 'total',
            'exchange_rate_snapshot',
            $userId,
        );

        return Invoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereIn('type', self::REVENUE_TYPES)
            ->whereIn('status', self::REVENUE_STATUSES)
            ->whereIn('client_id', $clientIds)
            ->selectRaw("client_id, {$czkSum['sql']} as czk_total", $czkSum['bindings'])
            ->groupBy('client_id')
            ->pluck('czk_total', 'client_id')
            ->map(fn (mixed $total): float => (float) $total)
            ->all();
    }
}
