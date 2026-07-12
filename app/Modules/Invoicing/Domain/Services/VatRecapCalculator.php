<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Models\QuoteItem;
use App\Modules\Shared\Enums\Currency;

/**
 * Single source of truth for VAT math shared by invoices and quotes:
 * per-rate buckets with the document-level discount applied proportionally,
 * rounded per bucket (Czech practice — VAT is computed from the recap, not
 * summed per item). The Invoice-typed methods below are thin wrappers around
 * the item-based core so both document types share identical rounding.
 */
final class VatRecapCalculator
{
    /**
     * @return list<VatRecapRow> sorted by rate ascending
     */
    public function recap(Invoice $invoice): array
    {
        return $this->recapFromItems(
            $invoice->items,
            $invoice->discount_percent !== null ? (float) $invoice->discount_percent : null,
        );
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
        return $this->subtotalFromItems($invoice->items);
    }

    public function discountAmount(Invoice $invoice): float
    {
        return $this->discountAmountFromItems(
            $invoice->items,
            $invoice->discount_percent !== null ? (float) $invoice->discount_percent : null,
        );
    }

    public function vatAmount(Invoice $invoice): float
    {
        return $this->vatAmountFromItems(
            $invoice->items,
            $invoice->discount_percent !== null ? (float) $invoice->discount_percent : null,
        );
    }

    /**
     * @return list<VatRecapRow> sorted by rate ascending
     */
    public function recapForQuote(Quote $quote): array
    {
        return $this->recapFromItems(
            $quote->items,
            $quote->discount_percent !== null ? (float) $quote->discount_percent : null,
        );
    }

    public function subtotalForQuote(Quote $quote): float
    {
        return $this->subtotalFromItems($quote->items);
    }

    public function discountAmountForQuote(Quote $quote): float
    {
        return $this->discountAmountFromItems(
            $quote->items,
            $quote->discount_percent !== null ? (float) $quote->discount_percent : null,
        );
    }

    public function vatAmountForQuote(Quote $quote): float
    {
        return $this->vatAmountFromItems(
            $quote->items,
            $quote->discount_percent !== null ? (float) $quote->discount_percent : null,
        );
    }

    /**
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     * @return list<VatRecapRow> sorted by rate ascending
     */
    public function recapFromItems(iterable $items, ?float $discountPercent): array
    {
        $factor = $this->discountFactor($discountPercent);

        /** @var array<string, float> $buckets rate => base excl. VAT before discount */
        $buckets = [];

        foreach ($items as $item) {
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
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     */
    public function subtotalFromItems(iterable $items): float
    {
        $sum = 0.0;

        foreach ($items as $item) {
            $sum += (float) $item->total_excl_vat;
        }

        return round($sum, 2);
    }

    /**
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     */
    public function discountAmountFromItems(iterable $items, ?float $discountPercent): float
    {
        if ($discountPercent === null) {
            return 0.0;
        }

        return round($this->subtotalFromItems($items) * $discountPercent / 100, 2);
    }

    /**
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     */
    public function vatAmountFromItems(iterable $items, ?float $discountPercent): float
    {
        return round(array_sum(array_map(
            static fn (VatRecapRow $row): float => $row->vat,
            $this->recapFromItems($items, $discountPercent),
        )), 2);
    }

    private function discountFactor(?float $discountPercent): float
    {
        return $discountPercent === null
            ? 1.0
            : 1.0 - $discountPercent / 100;
    }
}
