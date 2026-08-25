<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Auth\Application\Contracts\DashboardStatsContributor;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use Illuminate\Support\Facades\DB;

/**
 * Invoicing's two sections of the account dashboard.
 *
 * A contributor rather than SQL inside Auth's DashboardService, for the same
 * reason the premium modules are: which statuses count as "sent", how an
 * overdue balance nets partial payments, and that the income trend is cash-in
 * from the payment ledger rather than issued totals — all of that is knowledge
 * about `invoices` and `invoice_payments`, and it belongs where those tables
 * do.
 */
final class InvoicingDashboardStats implements DashboardStatsContributor
{
    /**
     * @return array<string, mixed>
     */
    public function statsFor(string $ownerId, int $year, int $month): array
    {
        return [
            'invoices' => $this->invoiceStats($ownerId, $year),
            'income_trend' => $this->incomeTrend($ownerId),
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
