<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Modules\Auth\Application\Contracts\DashboardStatsContributor;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Orders\Domain\Models\Order;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * @param  iterable<DashboardStatsContributor>  $contributors  Sections owned by other modules.
     */
    public function __construct(private readonly iterable $contributors = []) {}

    /**
     * @return array<string, mixed>
     */
    public function getStats(User $user): array
    {
        $userId = $user->accountOwnerId();
        $year = now()->year;
        $month = now()->month;

        $stats = [
            'clients' => $this->clientStats($userId),
            'orders' => $this->orderStats($userId),
            'invoices' => $this->invoiceStats($userId, $year),
            'income_trend' => $this->incomeTrend($userId),
        ];

        foreach ($this->contributors as $contributor) {
            $stats = [...$stats, ...$contributor->statsFor($userId, $year, $month)];
        }

        return $stats;
    }

    /**
     * @return array<string, int>
     */
    private function clientStats(string $userId): array
    {
        $counts = Client::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->selectRaw('count(*) as total')
            ->first();

        return [
            'total' => $counts ? (int) $counts->total : 0,
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

    /**
     * @return array<string, mixed>
     */
    private function invoiceStats(string $userId, int $year): array
    {
        $stats = Invoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereYear('issued_at', $year)
            ->selectRaw("
                count(*) as total,
                count(*) filter (where status = 'draft') as draft,
                count(*) filter (where status in ('issued', 'sent', 'reminded')) as sent,
                count(*) filter (where status = 'paid') as paid,
                coalesce(sum(total) filter (where status = 'paid'), 0) as revenue_paid,
                coalesce(sum(total) filter (where status in ('issued', 'sent', 'reminded')), 0) as revenue_pending
            ")
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'draft' => (int) ($stats->draft ?? 0),
            'sent' => (int) ($stats->sent ?? 0),
            'paid' => (int) ($stats->paid ?? 0),
            'revenue_paid' => (float) ($stats->revenue_paid ?? 0),
            'revenue_pending' => (float) ($stats->revenue_pending ?? 0),
            'volume' => $this->invoicingVolume($userId),
            'overdue' => $this->overdueStats($userId),
        ];
    }

    /**
     * Invoiced volume (sum of totals of issued invoices, excluding drafts and
     * cancelled) for the current month, quarter and year.
     *
     * @return array<string, float>
     */
    private function invoicingVolume(string $userId): array
    {
        $volume = Invoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->selectRaw(
                'coalesce(sum(total) filter (where issued_at >= ?), 0) as month,
                 coalesce(sum(total) filter (where issued_at >= ?), 0) as quarter,
                 coalesce(sum(total) filter (where issued_at >= ?), 0) as year',
                [
                    now()->startOfMonth()->toDateString(),
                    now()->startOfQuarter()->toDateString(),
                    now()->startOfYear()->toDateString(),
                ]
            )
            ->first();

        return [
            'month' => (float) ($volume->month ?? 0),
            'quarter' => (float) ($volume->quarter ?? 0),
            'year' => (float) ($volume->year ?? 0),
        ];
    }

    /**
     * Unpaid invoices past their due date: count and outstanding balance
     * (total minus recorded payments — respects partial payments).
     *
     * @return array<string, mixed>
     */
    private function overdueStats(string $userId): array
    {
        // Balances come from a join rather than a correlated subquery: the
        // latter re-runs per overdue invoice, which on a real book is tens of
        // thousands of index lookups where one hash join does. Each balance is
        // still rounded per invoice before being summed, as the PHP loop did —
        // rounding only the total would drift by a cent per partial payment.
        $balances = Invoice::withoutGlobalScope('user')
            ->leftJoin('invoice_payments', 'invoice_payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.user_id', $userId)
            ->whereIn('invoices.status', ['issued', 'sent', 'reminded'])
            ->where('invoices.due_at', '<', now()->toDateString())
            ->groupBy('invoices.id', 'invoices.total')
            ->selectRaw('round(invoices.total - coalesce(sum(invoice_payments.amount), 0), 2) as balance');

        $row = DB::query()
            ->fromSub($balances, 'balances')
            ->selectRaw('count(*) as document_count, coalesce(sum(balance), 0) as amount')
            ->first();

        // An aggregate with no GROUP BY always yields exactly one row.
        return [
            'count' => (int) ($row->document_count ?? 0),
            'amount' => round((float) ($row->amount ?? 0), 2),
        ];
    }

    /**
     * Twelve-month income timeline (cash-in): actually collected amounts from
     * the payment ledger, grouped by payment month, gaps filled with zero.
     *
     * @return array<int, array{month: string, amount: float}>
     */
    private function incomeTrend(string $userId): array
    {
        $start = now()->startOfMonth()->subMonths(11);

        $sums = InvoicePayment::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->where('invoices.user_id', $userId)
            ->where('invoice_payments.paid_at', '>=', $start->toDateString())
            ->selectRaw("to_char(date_trunc('month', invoice_payments.paid_at), 'YYYY-MM') as month,
                sum(invoice_payments.amount) as amount")
            ->groupBy('month')
            ->pluck('amount', 'month');

        $trend = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i)->format('Y-m');
            $trend[] = [
                'month' => $month,
                'amount' => (float) ($sums[$month] ?? 0),
            ];
        }

        return $trend;
    }
}
