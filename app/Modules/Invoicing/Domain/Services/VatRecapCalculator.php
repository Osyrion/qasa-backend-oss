<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Models\QuoteItem;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Support\Decimal;
use Brick\Math\BigDecimal;

/**
 * Single source of truth for VAT math shared by invoices and quotes:
 * per-rate buckets with the document-level discount applied proportionally,
 * rounded per bucket (Czech practice — VAT is computed from the recap, not
 * summed per item). The Invoice-typed methods below are thin wrappers around
 * the item-based core so both document types share identical rounding.
 *
 * All arithmetic runs on exact decimals. VatRecapRow is the boundary where
 * values become floats, and it is deliberately terminal: every row field is
 * already quantised to money scale, and nothing inside this class computes
 * from a row again — czkRecap() converts from the exact buckets, not from the
 * rounded output.
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
            $invoice->discount_percent,
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

        $rate = Decimal::of($invoice->exchange_rate_snapshot);

        // Converted from the exact per-bucket figures rather than from the
        // rounded rows, so the CZK recap is not a rounding of a rounding.
        return array_map(
            static fn (array $bucket): VatRecapRow => new VatRecapRow(
                (float) $bucket['rate'],
                (float) Decimal::money($bucket['base']->multipliedBy($rate)),
                (float) Decimal::money($bucket['vat']->multipliedBy($rate)),
                (float) Decimal::money($bucket['total']->multipliedBy($rate)),
            ),
            $this->bucketsFromItems($invoice->items, $invoice->discount_percent),
        );
    }

    public function subtotal(Invoice $invoice): float
    {
        return $this->subtotalFromItems($invoice->items);
    }

    public function discountAmount(Invoice $invoice): float
    {
        return $this->discountAmountFromItems($invoice->items, $invoice->discount_percent);
    }

    public function vatAmount(Invoice $invoice): float
    {
        return $this->vatAmountFromItems($invoice->items, $invoice->discount_percent);
    }

    /**
     * The document-level discount split across the VAT rates it applies to.
     *
     * EN 16931 does not accept a lump-sum discount: a document level allowance
     * (BT-92) carries its own VAT category and rate, and BR-S-08 checks that
     * each rate's taxable amount equals that rate's lines minus that rate's
     * allowances. So a single header percentage has to be resolved into one
     * allowance per rate before it can be written to a UBL document.
     *
     * Derived here rather than in the builder because it is the same
     * proportional split bucketsFromItems() already performs — computing it a
     * second time next to the XML is how an export starts disagreeing with
     * the invoice it represents.
     *
     * Each figure is the difference between the rate's undiscounted lines and
     * its quantised discounted base, so the allowances sum to exactly the
     * per-bucket rounding the recap used, not to a separately rounded total.
     *
     * @return array<string, float> rate (2 decimals, as a string) => allowance amount
     */
    public function discountByRate(Invoice $invoice): array
    {
        if ($invoice->discount_percent === null || (float) $invoice->discount_percent <= 0.0) {
            return [];
        }

        /** @var array<string, BigDecimal> $rawBases */
        $rawBases = [];

        foreach ($invoice->items as $item) {
            $rate = number_format((float) $item->vat_rate, 2, '.', '');
            $rawBases[$rate] = ($rawBases[$rate] ?? BigDecimal::zero())->plus(Decimal::of($item->total_excl_vat));
        }

        $allowances = [];

        foreach ($this->bucketsFromItems($invoice->items, $invoice->discount_percent) as $bucket) {
            $rate = (string) $bucket['rate'];
            $raw = $rawBases[$rate] ?? BigDecimal::zero();
            $allowance = Decimal::money($raw->minus($bucket['base']));

            if ((float) $allowance > 0.0) {
                $allowances[$rate] = (float) $allowance;
            }
        }

        return $allowances;
    }

    /**
     * @return list<VatRecapRow> sorted by rate ascending
     */
    public function recapForQuote(Quote $quote): array
    {
        return $this->recapFromItems($quote->items, $quote->discount_percent);
    }

    public function subtotalForQuote(Quote $quote): float
    {
        return $this->subtotalFromItems($quote->items);
    }

    public function discountAmountForQuote(Quote $quote): float
    {
        return $this->discountAmountFromItems($quote->items, $quote->discount_percent);
    }

    public function vatAmountForQuote(Quote $quote): float
    {
        return $this->vatAmountFromItems($quote->items, $quote->discount_percent);
    }

    /**
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     * @return list<VatRecapRow> sorted by rate ascending
     */
    public function recapFromItems(iterable $items, string|float|null $discountPercent): array
    {
        return array_map(
            static fn (array $bucket): VatRecapRow => new VatRecapRow(
                (float) $bucket['rate'],
                (float) (string) $bucket['base'],
                (float) (string) $bucket['vat'],
                (float) (string) $bucket['total'],
            ),
            $this->bucketsFromItems($items, $discountPercent),
        );
    }

    /**
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     */
    public function subtotalFromItems(iterable $items): float
    {
        $sum = Decimal::sum(array_map(
            static fn (InvoiceItem|QuoteItem $item): string => (string) $item->total_excl_vat,
            $this->itemsToList($items),
        ));

        return (float) Decimal::money($sum);
    }

    /**
     * Derived as subtotal minus the discounted bucket bases — not an
     * independent `subtotal * discountPercent` rounding — so that
     * `subtotal - discountAmount + vatAmount` (Invoice::recalculateTotals())
     * always equals `sum(bucket.base) + sum(bucket.vat)` (what
     * VatReportService and every recap() caller actually sum). Rounding the
     * discount once against the whole subtotal and rounding it once per
     * per-rate bucket are both individually correct, but on a multi-rate
     * document they can disagree by a cent — that used to make the invoice
     * header and the VAT report disagree by the same cent. See
     * ENGINEERING_GUARDRAILS_PLAN.md part B / VatReportInvariantTest, which
     * fuzzed this apart.
     *
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     */
    public function discountAmountFromItems(iterable $items, string|float|null $discountPercent): float
    {
        if ($discountPercent === null) {
            return 0.0;
        }

        $subtotal = Decimal::sum(array_map(
            static fn (InvoiceItem|QuoteItem $item): string => (string) $item->total_excl_vat,
            $this->itemsToList($items),
        ));

        $discountedBase = Decimal::sum(array_map(
            static fn (array $bucket): BigDecimal => $bucket['base'],
            $this->bucketsFromItems($items, $discountPercent),
        ));

        return (float) Decimal::money(Decimal::of($subtotal)->minus($discountedBase));
    }

    /**
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     */
    public function vatAmountFromItems(iterable $items, string|float|null $discountPercent): float
    {
        $vat = Decimal::sum(array_map(
            static fn (array $bucket): BigDecimal => $bucket['vat'],
            $this->bucketsFromItems($items, $discountPercent),
        ));

        return (float) Decimal::money($vat);
    }

    /**
     * Per-rate buckets with the discount applied, quantised to money scale but
     * still exact — the shape every public method above derives from.
     *
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     * @return list<array{rate: string, base: BigDecimal, vat: BigDecimal, total: BigDecimal}>
     */
    private function bucketsFromItems(iterable $items, string|float|null $discountPercent): array
    {
        $factor = $discountPercent === null
            ? BigDecimal::one()
            : BigDecimal::one()->minus(Decimal::percentOf(1, $discountPercent));

        /** @var array<string, BigDecimal> $bases rate => base excl. VAT before discount */
        $bases = [];

        foreach ($items as $item) {
            $rate = number_format((float) $item->vat_rate, 2, '.', '');
            $bases[$rate] = ($bases[$rate] ?? BigDecimal::zero())->plus(Decimal::of($item->total_excl_vat));
        }

        ksort($bases, SORT_NUMERIC);

        $buckets = [];

        foreach ($bases as $rate => $rawBase) {
            // Quantised per bucket, then VAT derived from that figure — the
            // rounded base is what the document shows and what VAT is due on.
            $base = Decimal::of(Decimal::money($rawBase->multipliedBy($factor)));
            $vat = Decimal::of(Decimal::money(Decimal::percentOf($base, (string) $rate)));

            $buckets[] = [
                'rate' => (string) $rate,
                'base' => $base,
                'vat' => $vat,
                'total' => $base->plus($vat),
            ];
        }

        return $buckets;
    }

    /**
     * @param  iterable<InvoiceItem|QuoteItem>  $items
     * @return list<InvoiceItem|QuoteItem>
     */
    private function itemsToList(iterable $items): array
    {
        return is_array($items) ? array_values($items) : iterator_to_array($items, false);
    }
}
