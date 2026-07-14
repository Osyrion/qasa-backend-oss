<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services\Statistics;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use Illuminate\Support\Carbon;

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
        $invoices = Invoice::withoutGlobalScope('user')
            ->where('user_id', $user->accountOwnerId())
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'sent', 'reminded'])
            ->withSum('payments as payments_sum_amount', 'amount')
            ->get(['id', 'total', 'currency', 'due_at']);

        $buckets = [];

        foreach ($invoices as $invoice) {
            $balance = round((float) $invoice->total - (float) $invoice->getAttribute('payments_sum_amount'), 2);
            $this->addToBucket($buckets, $invoice->currency->value, $this->bucketFor($invoice->due_at, $today), $balance);
        }

        return $this->zeroFillAndRound($buckets);
    }

    /**
     * @return array<string, array<string, array{amount: float, count: int}>>
     */
    private function payables(User $user, Carbon $today): array
    {
        $supplierInvoices = SupplierInvoice::withoutGlobalScope('user')
            ->where('user_id', $user->accountOwnerId())
            ->whereIn('status', ['received', 'booked'])
            ->get(['id', 'total', 'currency', 'due_at']);

        $buckets = [];

        foreach ($supplierInvoices as $invoice) {
            $this->addToBucket($buckets, $invoice->currency->value, $this->bucketFor($invoice->due_at, $today), (float) $invoice->total);
        }

        return $this->zeroFillAndRound($buckets);
    }

    /**
     * @param  array<string, array<string, array{amount: float, count: int}>>  $buckets
     */
    private function addToBucket(array &$buckets, string $currency, string $bucket, float $amount): void
    {
        $entry = $buckets[$currency][$bucket] ?? ['amount' => 0.0, 'count' => 0];
        $entry['amount'] += $amount;
        $entry['count'] += 1;
        $buckets[$currency][$bucket] = $entry;
    }

    private function bucketFor(?Carbon $dueAt, Carbon $today): string
    {
        if ($dueAt === null || $dueAt->greaterThanOrEqualTo($today)) {
            return 'not_yet_due';
        }

        $daysOverdue = (int) $dueAt->diffInDays($today);

        return match (true) {
            $daysOverdue <= 30 => 'd1_30',
            $daysOverdue <= 60 => 'd31_60',
            $daysOverdue <= 90 => 'd61_90',
            default => 'd90_plus',
        };
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
