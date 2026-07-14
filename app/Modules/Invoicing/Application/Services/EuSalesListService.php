<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\DTOs\EuSalesListRowData;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\Models\Invoice;

/**
 * Read-only basis for the EU sales list (súhrnný výkaz): issued intra-EU
 * reverse-charged invoices, grouped by month (taxable supply date, falling
 * back to issue date) and the client's frozen VAT ID snapshot — a later
 * edit to the client record must not retroactively change a filed period.
 * Draft, cancelled, storno and proforma documents don't represent a
 * completed taxable supply and are excluded.
 */
class EuSalesListService
{
    /**
     * @return list<EuSalesListRowData>
     */
    public function forPeriod(string $userId, int $year, ?int $quarter = null, ?int $month = null): array
    {
        $months = $this->monthsInScope($year, $quarter, $month);

        $invoices = Invoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('reverse_charge_mode', ReverseChargeMode::Eu->value)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereNotIn('type', [InvoiceType::Storno->value, InvoiceType::Proforma->value])
            ->get();

        /** @var array<string, array{period: string, vat_id: string, client_name: string, amount: float}> $buckets */
        $buckets = [];

        foreach ($invoices as $invoice) {
            $date = $invoice->taxable_supply_at ?? $invoice->issued_at;
            $period = $date->format('Y-m');

            if (! in_array($period, $months, true)) {
                continue;
            }

            $vatId = $invoice->client_snapshot['vat_id'] ?? null;

            if ($vatId === null || $vatId === '') {
                continue;
            }

            $key = $period.'|'.$vatId;

            $buckets[$key] ??= [
                'period' => $period,
                'vat_id' => $vatId,
                'client_name' => (string) ($invoice->client_snapshot['name'] ?? ''),
                'amount' => 0.0,
            ];

            $buckets[$key]['amount'] += (float) $invoice->total;
        }

        $rows = array_map(
            fn (array $row): EuSalesListRowData => new EuSalesListRowData(
                period: $row['period'],
                vatId: $row['vat_id'],
                clientName: $row['client_name'],
                amount: round($row['amount'], 2),
            ),
            array_values($buckets),
        );

        usort($rows, fn (EuSalesListRowData $a, EuSalesListRowData $b): int => [$a->period, $a->vatId] <=> [$b->period, $b->vatId]);

        return $rows;
    }

    /**
     * @return list<string> "Y-m" months in scope
     */
    private function monthsInScope(int $year, ?int $quarter, ?int $month): array
    {
        if ($month !== null) {
            return [sprintf('%04d-%02d', $year, $month)];
        }

        if ($quarter !== null) {
            $start = ($quarter - 1) * 3 + 1;

            return array_map(
                static fn (int $m): string => sprintf('%04d-%02d', $year, $m),
                range($start, $start + 2),
            );
        }

        return array_map(
            static fn (int $m): string => sprintf('%04d-%02d', $year, $m),
            range(1, 12),
        );
    }
}
