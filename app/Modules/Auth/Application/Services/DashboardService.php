<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;

class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function getStats(User $user): array
    {
        $userId = $user->accountOwnerId();
        $year = now()->year;
        $month = now()->month;

        return [
            'clients' => $this->clientStats($userId),
            'orders' => $this->orderStats($userId),
            'time' => $this->timeStats($userId, $year, $month),
            'invoices' => $this->invoiceStats($userId, $year),
            'income_trend' => $this->incomeTrend($userId),
            'running_timer' => $this->runningTimer($userId),
        ];
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
    private function timeStats(string $userId, int $year, int $month): array
    {
        $thisMonth = TimeEntry::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereYear('started_at', $year)
            ->whereMonth('started_at', $month)
            ->whereNotNull('ended_at')
            ->selectRaw('coalesce(sum(duration_seconds), 0) as total_seconds')
            ->value('total_seconds');

        $uninvoiced = TimeEntry::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('is_billable', true)
            ->where('is_invoiced', false)
            ->whereNotNull('ended_at')
            ->selectRaw('count(*) as count, coalesce(sum(duration_seconds), 0) as total_seconds')
            ->first();

        return [
            'this_month_seconds' => (int) $thisMonth,
            'this_month_hours' => round((int) $thisMonth / 3600, 2),
            'uninvoiced_count' => (int) ($uninvoiced?->count ?? 0),
            'uninvoiced_seconds' => (int) ($uninvoiced?->total_seconds ?? 0),
            'uninvoiced_hours' => round((int) ($uninvoiced?->total_seconds ?? 0) / 3600, 2),
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
            'total' => (int) ($stats?->total ?? 0),
            'draft' => (int) ($stats?->draft ?? 0),
            'sent' => (int) ($stats?->sent ?? 0),
            'paid' => (int) ($stats?->paid ?? 0),
            'revenue_paid' => (float) ($stats?->revenue_paid ?? 0),
            'revenue_pending' => (float) ($stats?->revenue_pending ?? 0),
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
        $overdue = Invoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereIn('status', ['issued', 'sent', 'reminded'])
            ->where('due_at', '<', now()->toDateString())
            ->withSum('payments as payments_sum_amount', 'amount')
            ->get(['id', 'total']);

        $amount = $overdue->sum(fn (Invoice $invoice): float => round(
            (float) $invoice->total - (float) $invoice->getAttribute('payments_sum_amount'),
            2,
        ));

        return [
            'count' => $overdue->count(),
            'amount' => round((float) $amount, 2),
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

        // Grouped in PHP (not SQL) so the month bucketing stays portable across
        // Postgres and the SQLite test connection. One user's 12-month payment
        // set is small, so a single query plus in-memory grouping is cheap.
        $sums = InvoicePayment::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->where('invoices.user_id', $userId)
            ->where('invoice_payments.paid_at', '>=', $start->toDateString())
            ->get(['invoice_payments.paid_at', 'invoice_payments.amount'])
            ->groupBy(fn (InvoicePayment $payment): string => $payment->paid_at->format('Y-m'))
            ->map(fn ($group): float => (float) $group->sum('amount'));

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

    /**
     * @return array<string, mixed>|null
     */
    private function runningTimer(string $userId): ?array
    {
        $entry = TimeEntry::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereNull('ended_at')
            ->with('order:id,name')
            ->first();

        if ($entry === null) {
            return null;
        }

        return [
            'id' => $entry->id,
            'description' => $entry->description,
            'started_at' => $entry->started_at?->toISOString(),
            'duration_seconds' => $entry->effectiveDurationSeconds(),
            'order' => [
                'id' => $entry->order?->id,
                'name' => $entry->order?->name,
            ],
        ];
    }
}
