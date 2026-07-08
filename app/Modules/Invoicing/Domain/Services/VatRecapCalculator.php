<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Enums\Currency;

/**
 * Single source of truth for invoice VAT math: per-rate buckets with the
 * invoice-level discount applied proportionally, rounded per bucket
 * (Czech practice — VAT is computed from the recap, not summed per item).
 */
final class VatRecapCalculator
{
    /**
     * @return list<VatRecapRow> sorted by rate ascending
     */
    public function recap(Invoice $invoice): array
    {
        $factor = $this->discountFactor($invoice);

        /** @var array<string, float> $buckets rate => base excl. VAT before discount */
        $buckets = [];

        foreach ($invoice->items as $item) {
            $rate = number_format((float) $item->vat_rate, 2, '.', '');
            $buckets[$rate] = ($buckets[$rate] ?? 0.0) + (float) $item->total_excl_vat;
        }

        ksort($buckets, SORT_NUMERIC);

        $rows = [];

        foreach ($buckets as $rate => $rawBase) {
            $rateFloat = (float) $rate;
            $base = round($rawBase * $factor, 2);
            $vat = round($base * $rateFloat / 100, 2);

            $rows[] = new VatRecapRow($rateFloat, $base, $vat, round($base + $vat, 2));
        }

        return $rows;
    }

    /**
     * Recap converted to CZK via the ČNB rate frozen at issue.
     *
     * @return list<VatRecapRow>|null null for CZK invoices or when no rate snapshot exists
     */
    public function czkRecap(Invoice $invoice): ?array
    {
        if ($invoice->currency === Currency::CZK || $invoice->exchange_rate_snapshot === null) {
            return null;
        }

        $rate = (float) $invoice->exchange_rate_snapshot;

        return array_map(
            static fn (VatRecapRow $row): VatRecapRow => new VatRecapRow(
                $row->rate,
                round($row->base * $rate, 2),
                round($row->vat * $rate, 2),
                round($row->total * $rate, 2),
            ),
            $this->recap($invoice),
        );
    }

    public function subtotal(Invoice $invoice): float
    {
        return round((float) $invoice->items->sum('total_excl_vat'), 2);
    }

    public function discountAmount(Invoice $invoice): float
    {
        if ($invoice->discount_percent === null) {
            return 0.0;
        }

        return round($this->subtotal($invoice) * (float) $invoice->discount_percent / 100, 2);
    }

    public function vatAmount(Invoice $invoice): float
    {
        return round(array_sum(array_map(static fn (VatRecapRow $row): float => $row->vat, $this->recap($invoice))), 2);
    }

    private function discountFactor(Invoice $invoice): float
    {
        return $invoice->discount_percent === null
            ? 1.0
            : 1.0 - (float) $invoice->discount_percent / 100;
    }
}
