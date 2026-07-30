<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Cz;

use App\Modules\Invoicing\Application\DTOs\EuSalesListRowData;
use App\Modules\Invoicing\Application\Services\EuSalesListService;
use App\Modules\Taxation\Domain\Contracts\EuSalesListBuilder;

/**
 * CZ souhrnné hlášení — groups issued intra-EU reverse-charged invoices by
 * month and client VAT ID. Copy of SkEuSalesListBuilder — currently
 * identical, split ahead of divergence per the plan's "duplication is
 * deliberate" rule.
 */
final class CzEuSalesListBuilder implements EuSalesListBuilder
{
    public function __construct(
        private readonly EuSalesListService $collector,
    ) {}

    public function build(string $userId, int $year, ?int $quarter = null, ?int $month = null): array
    {
        $months = $this->collector->monthsInScope($year, $quarter, $month);
        $invoices = $this->collector->collect($userId, $months);

        /** @var array<string, array{period: string, vat_id: string, client_name: string, amount: float}> $buckets */
        $buckets = [];

        foreach ($invoices as $invoice) {
            $date = $invoice->taxable_supply_at ?? $invoice->issued_at;
            $period = $date->format('Y-m');

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
}
