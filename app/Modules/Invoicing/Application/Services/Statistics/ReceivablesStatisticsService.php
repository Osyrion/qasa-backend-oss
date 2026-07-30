<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aging buckets for open receivables (client invoices) and payables
 * (supplier invoices), per currency, in real cash terms (VAT included —
 * these are amounts actually owed/due, not the tax-basis revenue figures
 * used elsewhere in the dashboard).
 */
final readonly class ReceivablesStatisticsService
{
    private const BUCKETS = ['not_yet_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    /**
     * @return array<string, mixed>
     */
    public function getStatistics(User $user): array
    {
        $today = Carbon::now()->startOfDay();

        return [
            'as_of' => $today->toDateString(),
            'receivables' => $this->receivables($user, $today),
            'payables' => $this->payables($user, $today),
        ];
    }

    /**
     * @return array<string, array<string, array{amount: float, count: int}>>
     */
    private function receivables(User $user, Carbon $today): array
    {
        // Balances come from a join, not a correlated subquery — the latter
        // re-runs once per open invoice. Each is rounded per document before
        // being summed, as the PHP loop did; rounding only the total would
        // drift once partial payments are involved.
        return $this->bucketed(
            Invoice::withoutGlobalScope('user')
                ->leftJoin('invoice_payments', 'invoice_payments.invoice_id', '=', 'invoices.id')
                ->where('invoices.user_id', $user->accountOwnerId())
                ->where('invoices.type', 'invoice')
                ->whereIn('invoices.status', ['issued', 'sent', 'reminded'])
                ->groupBy('invoices.id', 'invoices.currency', 'invoices.due_at', 'invoices.total')
                ->selectRaw('invoices.currency as currency_code, invoices.due_at,
                    round(invoices.total - coalesce(sum(invoice_payments.amount), 0), 2) as amount'),
            $today,
        );
    }

    /**
     * @return array<string, array<string, array{amount: float, count: int}>>
     */
    private function payables(User $user, Carbon $today): array
    {
        // No partial payments are tracked against supplier invoices, so the
        // full total falls due and one rounding at the end is enough.
        return $this->bucketed(
            SupplierInvoice::withoutGlobalScope('user')
                ->where('user_id', $user->accountOwnerId())
                ->whereIn('status', ['received', 'booked'])
                ->selectRaw('currency as currency_code, due_at, total as amount'),
            $today,
        );
    }

    /**
     * Aggregate a per-document row set into per-currency aging buckets.
     *
     * $documents must yield currency_code, due_at and amount per document —
     * currency is aliased so the enum cast does not turn a bucket key into an
     * object. due_at and the reference day are both plain dates, so
     * date - date is the whole-day overdue count the Carbon diff produced.
     *
     * @param  Builder<Invoice>|Builder<SupplierInvoice>  $documents
     * @return array<string, array<string, array{amount: float, count: int}>>
     */
    private function bucketed(Builder $documents, Carbon $today): array
    {
        $day = $today->toDateString();

        $rows = DB::query()
            ->fromSub($documents, 'documents')
            ->selectRaw(
                "currency_code,
                 CASE
                     WHEN due_at IS NULL OR due_at >= ?::date THEN 'not_yet_due'
                     WHEN ?::date - due_at <= 30 THEN 'd1_30'
                     WHEN ?::date - due_at <= 60 THEN 'd31_60'
                     WHEN ?::date - due_at <= 90 THEN 'd61_90'
                     ELSE 'd90_plus'
                 END as bucket,
                 count(*) as document_count,
                 round(sum(amount), 2) as amount",
                [$day, $day, $day, $day],
            )
            ->groupBy('currency_code', 'bucket')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $buckets[(string) $row->currency_code][(string) $row->bucket] = [
                'amount' => (float) $row->amount,
                'count' => (int) $row->document_count,
            ];
        }

        return $this->zeroFillAndRound($buckets);
    }

    /**
     * @param  array<string, array<string, array{amount: float, count: int}>>  $buckets
     * @return array<string, array<string, array{amount: float, count: int}>>
     */
    private function zeroFillAndRound(array $buckets): array
    {
        $result = [];

        foreach ($buckets as $currency => $currencyBuckets) {
            foreach (self::BUCKETS as $bucket) {
                $result[$currency][$bucket] = [
                    'amount' => round($currencyBuckets[$bucket]['amount'] ?? 0.0, 2),
                    'count' => (int) ($currencyBuckets[$bucket]['count'] ?? 0),
                ];
            }
        }

        return $result;
    }
}
