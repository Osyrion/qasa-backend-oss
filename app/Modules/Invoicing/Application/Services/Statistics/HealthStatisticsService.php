<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use Illuminate\Support\Carbon;

/**
 * Financial health metrics over the trailing 12 months: DSO/DPO, payment
 * morale, client/supplier revenue concentration, and the working capital
 * cycle. Concentration figures are converted to the user's default currency
 * (unlike PartnerStatisticsService's native-currency rankings) because they
 * feed a single risk score, not a per-currency leaderboard.
 *
 * DPO and the per-partner sums are computed entirely in SQL. The payment
 * morale loop stays in PHP over a flat row set: the day arithmetic is
 * Carbon's, and reproducing it in SQL would risk shifting the published
 * figures for no gain — the expensive part was loading each invoice's
 * payments, which a correlated subquery replaces.
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
    public function getStatistics(Account&ProvidesSupplierProfile $user): array
    {
        $range = (new StatisticsPeriods)->rolling12();

        $dso = $this->dsoAndMorale($user, $range);
        $dpo = $this->dpo($user, $range);
        $clientConcentration = $this->concentration($user, $range, revenue: true);
        $supplierConcentration = $this->concentration($user, $range, revenue: false);

        return [
            'currency' => $user->supplierProfile()->defaultCurrency->value,
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
    private function dsoAndMorale(Account&ProvidesSupplierProfile $user, array $range): array
    {
        // withMax rather than with('payments'): only the last payment date
        // matters, so a correlated subquery replaces loading every payment
        // row of every invoice. It does not inherit the related model's cast,
        // hence the explicit withCasts.
        $invoices = Invoice::withoutGlobalScope('user')
            ->where('user_id', $user->accountOwnerId())
            ->where('type', 'invoice')
            ->where('status', 'paid')
            ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$range['from'], $range['to']])
            ->withMax('payments', 'paid_at')
            ->withCasts(['payments_max_paid_at' => 'date'])
            ->get(['id', 'issued_at', 'due_at']);

        $totalDaysToPay = 0;
        $onTimeCount = 0;
        $totalDaysLate = 0;
        $lateCount = 0;
        $sampleSize = 0;

        foreach ($invoices as $invoice) {
            /** @var Carbon|null $lastPaymentAt */
            $lastPaymentAt = $invoice->getAttribute('payments_max_paid_at');

            if ($lastPaymentAt === null) {
                continue;
            }

            $sampleSize++;
            $totalDaysToPay += $invoice->issued_at->diffInDays($lastPaymentAt);

            if ($lastPaymentAt->lessThanOrEqualTo($invoice->due_at)) {
                $onTimeCount++;
            } else {
                $lateCount++;
                $totalDaysLate += $invoice->due_at->diffInDays($lastPaymentAt);
            }
        }

        if ($sampleSize === 0) {
            return [
                'dso' => null,
                'on_time_percent' => null,
                'late_percent' => null,
                'avg_days_late' => null,
                'sample_size' => 0,
            ];
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
    private function dpo(Account&ProvidesSupplierProfile $user, array $range): array
    {
        // Both columns are plain dates, so date - date gives whole days —
        // the same integer the Carbon diff produced.
        $row = SupplierInvoice::withoutGlobalScope('user')
            ->where('user_id', $user->accountOwnerId())
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$range['from'], $range['to']])
            ->selectRaw('COUNT(*) as sample_size, AVG(paid_at - issued_at) as days')
            ->first();

        // An aggregate with no GROUP BY always yields one row, so the
        // nullsafe is belt and braces — and a null row would read as
        // sample_size 0 and take the same early return anyway.
        $sampleSize = (int) $row?->getAttribute('sample_size');

        if ($sampleSize === 0) {
            return ['days' => null, 'sample_size' => 0];
        }

        return ['days' => round((float) $row->getAttribute('days'), 1), 'sample_size' => $sampleSize];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array{top1_share_percent: ?float, risk_level: ?string, pareto_count: ?int}
     */
    private function concentration(Account&ProvidesSupplierProfile $user, array $range, bool $revenue): array
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
    private function partnerAmountsInDefaultCurrency(Account&ProvidesSupplierProfile $user, array $range, bool $revenue): array
    {
        $userId = $user->accountOwnerId();
        $amountColumn = $user->supplierProfile()->vatStatus->isVatPayer() ? 'subtotal' : 'total';

        $query = $revenue
            ? Invoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereIn('type', self::REVENUE_TYPES)
                ->whereIn('status', self::REVENUE_STATUSES)
            : SupplierInvoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereIn('status', self::COST_STATUSES);

        $czkSum = $this->currencyConverter->czkSum(
            $amountColumn,
            $revenue ? 'exchange_rate_snapshot' : 'exchange_rate',
            $userId,
        );

        $czkByPartner = $query
            ->whereRaw('COALESCE(taxable_supply_at, issued_at) BETWEEN ? AND ?', [$range['from'], $range['to']])
            ->whereNotNull('client_id')
            ->selectRaw("client_id, {$czkSum['sql']} as czk_total", $czkSum['bindings'])
            ->groupBy('client_id')
            ->pluck('czk_total', 'client_id');

        $defaultByPartner = [];
        foreach ($czkByPartner as $partnerId => $czk) {
            $defaultByPartner[(string) $partnerId] = round(
                $this->currencyConverter->czkToDefault((float) $czk, $user),
                2,
            );
        }

        return $defaultByPartner;
    }
}
