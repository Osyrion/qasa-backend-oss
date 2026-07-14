<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getStatistics(User $user, int $limit): array
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
    private function topPartners(User $user, array $range, int $limit, bool $revenue): array
    {
        $userId = $user->accountOwnerId();
        $amountColumn = $user->is_vat_payer ? 'subtotal' : 'total';

        $rows = $revenue
            ? Invoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereIn('type', self::REVENUE_TYPES)
                ->whereIn('status', self::REVENUE_STATUSES)
                ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$range['from'], $range['to']])
                ->whereNotNull('client_id')
                ->selectRaw("client_id, currency, SUM({$amountColumn}) as amount")
                ->groupBy('client_id', 'currency')
                ->get()
            : SupplierInvoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereIn('status', self::COST_STATUSES)
                ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$range['from'], $range['to']])
                ->whereNotNull('client_id')
                ->selectRaw("client_id, currency, SUM({$amountColumn}) as amount")
                ->groupBy('client_id', 'currency')
                ->get();

        $byCurrency = [];
        foreach ($rows as $row) {
            /** @var Currency $currency */
            $currency = $row->currency;
            $currencyValue = $currency->value;

            /** @var float|int|string|null $rawAmount */
            $rawAmount = $row->getAttribute('amount');
            $byCurrency[$currencyValue][$row->client_id] = ($byCurrency[$currencyValue][$row->client_id] ?? 0.0) + (float) $rawAmount;
        }

        $clients = Client::withoutGlobalScope('user')
            ->withTrashed()
            ->whereIn('id', $rows->pluck('client_id')->unique()->values())
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($byCurrency as $currency => $amounts) {
            $total = array_sum($amounts);
            arsort($amounts);
            $top = array_slice($amounts, 0, $limit, true);

            $result[$currency] = array_map(
                fn (float $amount, string $clientId): array => [
                    'client_id' => $clientId,
                    'name' => $clients->get($clientId)?->display_name,
                    'amount' => round($amount, 2),
                    'percent_share' => $total > 0.0 ? round($amount / $total * 100, 1) : null,
                ],
                array_values($top),
                array_keys($top),
            );
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function churnRisk(User $user, StatisticsPeriods $periods): array
    {
        $userId = $user->accountOwnerId();
        $cutoff = $periods->today()->copy()->subDays(self::CHURN_DAYS)->toDateString();

        /** @var Collection<int, object{client_id: string, last_invoice_at: string}> $lastInvoiceRows */
        $lastInvoiceRows = Invoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereIn('type', self::REVENUE_TYPES)
            ->whereIn('status', self::REVENUE_STATUSES)
            ->whereNotNull('client_id')
            ->selectRaw('client_id, MAX(COALESCE(taxable_supply_at, issued_at)) as last_invoice_at')
            ->groupBy('client_id')
            ->havingRaw('MAX(COALESCE(taxable_supply_at, issued_at)) <= ?', [$cutoff])
            ->get();

        if ($lastInvoiceRows->isEmpty()) {
            return [];
        }

        $customerIds = Client::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('is_customer', true)
            ->whereIn('id', $lastInvoiceRows->pluck('client_id'))
            ->pluck('id')
            ->all();

        $lastInvoiceRows = $lastInvoiceRows->filter(
            fn (object $row): bool => in_array($row->client_id, $customerIds, true),
        );

        if ($lastInvoiceRows->isEmpty()) {
            return [];
        }

        $lifetimeCzkByClient = $this->lifetimeRevenueInCzk($user, array_values($lastInvoiceRows->pluck('client_id')->all()));

        $clients = Client::withoutGlobalScope('user')
            ->withTrashed()
            ->whereIn('id', $lastInvoiceRows->pluck('client_id'))
            ->get()
            ->keyBy('id');

        $today = $periods->today();

        return array_values($lastInvoiceRows
            ->map(function (object $row) use ($clients, $lifetimeCzkByClient, $user, $today): array {
                $lastInvoiceAt = Carbon::parse($row->last_invoice_at);
                $lifetimeCzk = $lifetimeCzkByClient[$row->client_id] ?? 0.0;

                return [
                    'client_id' => $row->client_id,
                    'name' => $clients->get($row->client_id)?->display_name,
                    'last_invoice_at' => $lastInvoiceAt->toDateString(),
                    'days_since_last_invoice' => (int) $lastInvoiceAt->diffInDays($today),
                    'lifetime_revenue' => round($this->currencyConverter->czkToDefault($lifetimeCzk, $user), 2),
                    'currency' => $user->default_currency->value,
                ];
            })
            ->sortByDesc('days_since_last_invoice')
            ->values()
            ->all());
    }

    /**
     * @param  list<string>  $clientIds
     * @return array<string, float>
     */
    private function lifetimeRevenueInCzk(User $user, array $clientIds): array
    {
        $userId = $user->accountOwnerId();
        $amountColumn = $user->is_vat_payer ? 'subtotal' : 'total';

        $rows = Invoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereIn('type', self::REVENUE_TYPES)
            ->whereIn('status', self::REVENUE_STATUSES)
            ->whereIn('client_id', $clientIds)
            ->selectRaw("
                client_id, currency,
                SUM(CASE WHEN currency = 'CZK' THEN {$amountColumn}
                         WHEN exchange_rate_snapshot IS NOT NULL THEN {$amountColumn} * exchange_rate_snapshot
                         END) AS converted_czk,
                COALESCE(SUM({$amountColumn}) FILTER (WHERE currency <> 'CZK' AND exchange_rate_snapshot IS NULL), 0) AS unconverted
            ")
            ->groupBy('client_id', 'currency')
            ->get();

        $czkByClient = [];
        foreach ($rows as $row) {
            /** @var Currency $currency */
            $currency = $row->currency;

            /** @var float|int|string|null $rawCzk */
            $rawCzk = $row->getAttribute('converted_czk');

            /** @var float|int|string|null $rawUnconverted */
            $rawUnconverted = $row->getAttribute('unconverted');

            $czk = (float) $rawCzk;
            $unconverted = (float) $rawUnconverted;

            if ($unconverted !== 0.0) {
                $czk += $unconverted * $this->currencyConverter->fallbackRateToCzk($currency, $userId);
            }

            $czkByClient[$row->client_id] = ($czkByClient[$row->client_id] ?? 0.0) + $czk;
        }

        return $czkByClient;
    }
}
