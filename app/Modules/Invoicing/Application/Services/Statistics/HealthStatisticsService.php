<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Financial health metrics over the trailing 12 months: DSO/DPO, payment
 * morale, client/supplier revenue concentration, and the working capital
 * cycle. Concentration figures are converted to the user's default currency
 * (unlike PartnerStatisticsService's native-currency rankings) because they
 * feed a single risk score, not a per-currency leaderboard.
 */
final readonly class HealthStatisticsService
{
    private const REVENUE_TYPES = ['invoice', 'credit_note'];

    private const REVENUE_STATUSES = ['issued', 'sent', 'reminded', 'paid', 'credited'];

    private const COST_STATUSES = ['received', 'booked', 'paid'];

    public function __construct(
        private StatisticsCurrencyConverter $currencyConverter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getStatistics(User $user): array
    {
        $range = (new StatisticsPeriods)->rolling12();

        $dso = $this->dsoAndMorale($user, $range);
        $dpo = $this->dpo($user, $range);
        $clientConcentration = $this->concentration($user, $range, revenue: true);
        $supplierConcentration = $this->concentration($user, $range, revenue: false);

        return [
            'currency' => $user->default_currency->value,
            'dso' => ['days' => $dso['dso'], 'sample_size' => $dso['sample_size']],
            'payment_morale' => [
                'on_time_percent' => $dso['on_time_percent'],
                'late_percent' => $dso['late_percent'],
                'avg_days_late' => $dso['avg_days_late'],
                'sample_size' => $dso['sample_size'],
            ],
            'client_concentration' => $clientConcentration,
            'dpo' => $dpo,
            'supplier_concentration' => $supplierConcentration,
            'working_capital_cycle_days' => $dso['dso'] !== null && $dpo['days'] !== null
                ? round($dso['dso'] - $dpo['days'], 1)
                : null,
        ];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array{dso: ?float, on_time_percent: ?float, late_percent: ?float, avg_days_late: ?float, sample_size: int}
     */
    private function dsoAndMorale(User $user, array $range): array
    {
        $rows = DB::select("
            SELECT i.issued_at, i.due_at, last_payment.paid_at
            FROM invoices i
            JOIN LATERAL (
                SELECT MAX(paid_at) as paid_at FROM invoice_payments WHERE invoice_id = i.id
            ) last_payment ON true
            WHERE i.user_id = ? AND i.type = 'invoice' AND i.status = 'paid'
              AND i.deleted_at IS NULL
              AND COALESCE(i.taxable_supply_at, i.issued_at) BETWEEN ? AND ?
              AND last_payment.paid_at IS NOT NULL
        ", [$user->accountOwnerId(), $range['from'], $range['to']]);

        $sampleSize = count($rows);

        if ($sampleSize === 0) {
            return [
                'dso' => null,
                'on_time_percent' => null,
                'late_percent' => null,
                'avg_days_late' => null,
                'sample_size' => 0,
            ];
        }

        $totalDaysToPay = 0;
        $onTimeCount = 0;
        $totalDaysLate = 0;
        $lateCount = 0;

        foreach ($rows as $row) {
            $issuedAt = Carbon::parse($row->issued_at);
            $dueAt = Carbon::parse($row->due_at);
            $paidAt = Carbon::parse($row->paid_at);

            $totalDaysToPay += $issuedAt->diffInDays($paidAt);

            if ($paidAt->lessThanOrEqualTo($dueAt)) {
                $onTimeCount++;
            } else {
                $lateCount++;
                $totalDaysLate += $dueAt->diffInDays($paidAt);
            }
        }

        return [
            'dso' => round($totalDaysToPay / $sampleSize, 1),
            'on_time_percent' => round($onTimeCount / $sampleSize * 100, 1),
            'late_percent' => round($lateCount / $sampleSize * 100, 1),
            'avg_days_late' => $lateCount > 0 ? round($totalDaysLate / $lateCount, 1) : null,
            'sample_size' => $sampleSize,
        ];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array{days: ?float, sample_size: int}
     */
    private function dpo(User $user, array $range): array
    {
        $rows = SupplierInvoice::withoutGlobalScope('user')
            ->where('user_id', $user->accountOwnerId())
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$range['from'], $range['to']])
            ->get(['issued_at', 'paid_at']);

        $sampleSize = $rows->count();

        if ($sampleSize === 0) {
            return ['days' => null, 'sample_size' => 0];
        }

        $totalDays = $rows->sum(
            fn (SupplierInvoice $invoice): int => (int) $invoice->issued_at->diffInDays($invoice->paid_at),
        );

        return ['days' => round($totalDays / $sampleSize, 1), 'sample_size' => $sampleSize];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array{top1_share_percent: ?float, risk_level: ?string, pareto_count: ?int}
     */
    private function concentration(User $user, array $range, bool $revenue): array
    {
        $amounts = $this->partnerAmountsInDefaultCurrency($user, $range, $revenue);

        $total = array_sum($amounts);

        if ($total <= 0.0 || $amounts === []) {
            return ['top1_share_percent' => null, 'risk_level' => null, 'pareto_count' => null];
        }

        arsort($amounts);
        $values = array_values($amounts);

        $top1SharePercent = round($values[0] / $total * 100, 1);

        $riskLevel = match (true) {
            $top1SharePercent > 40.0 => 'high',
            $top1SharePercent >= 25.0 => 'medium',
            default => 'low',
        };

        $cumulative = 0.0;
        $paretoCount = 0;
        foreach ($values as $value) {
            $cumulative += $value;
            $paretoCount++;

            if ($cumulative / $total >= 0.8) {
                break;
            }
        }

        return [
            'top1_share_percent' => $top1SharePercent,
            'risk_level' => $riskLevel,
            'pareto_count' => $paretoCount,
        ];
    }

    /**
     * Per-partner (client or vendor) revenue/cost for the period, converted
     * into the user's default currency.
     *
     * @param  array{from: string, to: string}  $range
     * @return array<string, float>
     */
    private function partnerAmountsInDefaultCurrency(User $user, array $range, bool $revenue): array
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
                ->selectRaw("
                    client_id, currency,
                    SUM(CASE WHEN currency = 'CZK' THEN {$amountColumn}
                             WHEN exchange_rate_snapshot IS NOT NULL THEN {$amountColumn} * exchange_rate_snapshot
                             END) AS converted_czk,
                    COALESCE(SUM({$amountColumn}) FILTER (WHERE currency <> 'CZK' AND exchange_rate_snapshot IS NULL), 0) AS unconverted
                ")
                ->groupBy('client_id', 'currency')
                ->get()
            : SupplierInvoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereIn('status', self::COST_STATUSES)
                ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$range['from'], $range['to']])
                ->whereNotNull('client_id')
                ->selectRaw("
                    client_id, currency,
                    SUM(CASE WHEN currency = 'CZK' THEN {$amountColumn}
                             WHEN exchange_rate IS NOT NULL THEN {$amountColumn} * exchange_rate
                             END) AS converted_czk,
                    COALESCE(SUM({$amountColumn}) FILTER (WHERE currency <> 'CZK' AND exchange_rate IS NULL), 0) AS unconverted
                ")
                ->groupBy('client_id', 'currency')
                ->get();

        $czkByPartner = [];
        foreach ($rows as $row) {
            /** @var Currency $currency */
            $currency = $row->currency;
            $czk = (float) $row->converted_czk;
            $unconverted = (float) $row->unconverted;

            if ($unconverted !== 0.0) {
                $czk += $unconverted * $this->currencyConverter->fallbackRateToCzk($currency, $userId);
            }

            $czkByPartner[$row->client_id] = ($czkByPartner[$row->client_id] ?? 0.0) + $czk;
        }

        $defaultByPartner = [];
        foreach ($czkByPartner as $partnerId => $czk) {
            $defaultByPartner[$partnerId] = round($this->currencyConverter->czkToDefault($czk, $user), 2);
        }

        return $defaultByPartner;
    }
}
