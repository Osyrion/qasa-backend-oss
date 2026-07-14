<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Models\QuoteItem;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Support\Decimal;

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

        $rate = (string) $invoice->exchange_rate_snapshot;

        return array_map(
            static fn (VatRecapRow $row): VatRecapRow => new VatRecapRow(
                $row->rate,
                (float) Decimal::mul((string) $row->base, $rate),
                (float) Decimal::mul((string) $row->vat, $rate),
                (float) Decimal::mul((string) $row->total, $rate),
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

        /** @var array<string, numeric-string> $buckets rate => base excl. VAT before discount */
        $buckets = [];

        foreach ($items as $item) {
            $rate = number_format((float) $item->vat_rate, 2, '.', '');
            $buckets[$rate] = Decimal::add($buckets[$rate] ?? '0', (string) $item->total_excl_vat, 10);
        }

        ksort($buckets, SORT_NUMERIC);

        $rows = [];

        foreach ($buckets as $rate => $rawBase) {
            $rateFloat = (float) $rate;
            $base = Decimal::round(Decimal::mul($rawBase, $factor, 10));
            $vat = Decimal::round(Decimal::mul($base, Decimal::div((string) $rateFloat, '100', 10), 10));

            $rows[] = new VatRecapRow($rateFloat, (float) $base, (float) $vat, (float) Decimal::add($base, $vat));
        }

        return $rows;
    }

    /**
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     */
    public function subtotalFromItems(iterable $items): float
    {
        $sum = '0';

        foreach ($items as $item) {
            $sum = Decimal::add($sum, (string) $item->total_excl_vat, 10);
        }

        return (float) Decimal::round($sum);
    }

    /**
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     */
    public function discountAmountFromItems(iterable $items, ?float $discountPercent): float
    {
        if ($discountPercent === null) {
            return 0.0;
        }

        $subtotal = (string) $this->subtotalFromItems($items);

        return (float) Decimal::round(Decimal::mul($subtotal, Decimal::div((string) $discountPercent, '100', 10), 10));
    }

    /**
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     */
    public function vatAmountFromItems(iterable $items, ?float $discountPercent): float
    {
        $sum = '0';

        foreach ($this->recapFromItems($items, $discountPercent) as $row) {
            $sum = Decimal::add($sum, (string) $row->vat);
        }

        return (float) $sum;
    }

    /**
     * @return numeric-string
     */
    private function discountFactor(?float $discountPercent): string
    {
        return $discountPercent === null
            ? '1'
            : Decimal::sub('1', Decimal::div((string) $discountPercent, '100', 10), 10);
    }
}
