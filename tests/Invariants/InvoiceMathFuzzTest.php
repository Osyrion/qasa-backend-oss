<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Shared\Support\Decimal;

/**
 * `ENGINEERING_GUARDRAILS_PLAN.md` part B: hundreds of randomized iterations
 * over the invoice/quote money math, seed printed on failure so a break is
 * reproducible. Every invariant here was checked against the real
 * implementation first (CalculatesLineTotals, Decimal, VatRecapCalculator,
 * SettleProformaAction, CreateCorrectiveInvoiceAction) — some things the plan
 * assumed turned out not to hold exactly in this codebase:
 *
 *  - There is no `prices_include_vat` flag anywhere in this repo (grepped the
 *    whole tree). `unit_price` is always Excl. VAT — there is only one price
 *    mode, so there is nothing to "preserve" across copy/corrective/recurring
 *    generation beyond unit_price/vat_rate themselves, which the corrective
 *    and settle tests below already cover.
 *  - `invoice.vat_amount` (per-rate buckets, VatRecapCalculator) can
 *    legitimately differ from a naive `sum(item.vat_amount)` by a cent or two
 *    per distinct VAT rate on the document — rounding the combined bucket
 *    base once vs. rounding each item independently are both individually
 *    "correct" roundings that don't have to agree. This is exercised and
 *    bounded below, not asserted away.
 *
 * VAT rates are drawn from the SK ([0, 5, 10, 23]) and CZ ([0, 12, 21])
 * catalogs plus a couple of out-of-catalog values, since InvoiceItem does not
 * enforce the VatRate catalog at the row level.
 */

/** @return numeric-string e.g. "1234.56" */
function fuzzMoney(int $minCents, int $maxCents): string
{
    return number_format(mt_rand($minCents, $maxCents) / 100, 2, '.', '');
}

/** @return numeric-string e.g. "12.345" */
function fuzzQuantity(int $minThousandths, int $maxThousandths): string
{
    return number_format(mt_rand($minThousandths, $maxThousandths) / 1000, 3, '.', '');
}

/** @return numeric-string */
function fuzzVatRate(): string
{
    $catalog = ['0', '5', '10', '12', '21', '23', '15', '19'];

    return $catalog[array_rand($catalog)];
}

function fuzzDiscountPercent(): ?string
{
    return match (mt_rand(0, 2)) {
        0 => null,
        1 => '0.00',
        default => number_format(mt_rand(1, 9999) / 100, 2, '.', ''),
    };
}

/**
 * @return list<InvoiceItem>
 */
function fuzzUnsavedItems(int $count): array
{
    $items = [];

    for ($i = 0; $i < $count; $i++) {
        $item = new InvoiceItem([
            'quantity' => fuzzQuantity(1, 999_000),
            'unit_price' => fuzzMoney(0, 999_999),
            'vat_rate' => fuzzVatRate(),
        ]);
        $item->recalculate();
        $items[] = $item;
    }

    return $items;
}

it('computes item totals via the documented two-step rounding: round(qty*price) first, VAT derived from that', function (): void {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);

    for ($i = 0; $i < 300; $i++) {
        $quantity = fuzzQuantity(0, 999_000);
        $unitPrice = fuzzMoney(0, 9_999_999);
        $vatRate = fuzzVatRate();

        $item = new InvoiceItem(['quantity' => $quantity, 'unit_price' => $unitPrice, 'vat_rate' => $vatRate]);
        $item->recalculate();

        $expectedExcl = Decimal::money(Decimal::of($quantity)->multipliedBy(Decimal::of($unitPrice)));
        $expectedVat = Decimal::money(Decimal::percentOf($expectedExcl, $vatRate));
        $expectedIncl = Decimal::money(Decimal::of($expectedExcl)->plus(Decimal::of($expectedVat)));

        $message = "seed={$seed} iteration={$i} qty={$quantity} price={$unitPrice} rate={$vatRate}";

        expect(Decimal::of($item->total_excl_vat)->isEqualTo(Decimal::of($expectedExcl)))->toBeTrue($message)
            ->and(Decimal::of($item->vat_amount)->isEqualTo(Decimal::of($expectedVat)))->toBeTrue($message)
            ->and(Decimal::of($item->total_incl_vat)->isEqualTo(Decimal::of($expectedIncl)))->toBeTrue($message)
            ->and(
                Decimal::of($item->total_excl_vat)->plus(Decimal::of($item->vat_amount))
                    ->isEqualTo(Decimal::of($item->total_incl_vat))
            )->toBeTrue("base+VAT=total: {$message}");
    }
});

it('never produces a negative line total from non-negative inputs, and a zero VAT rate zeroes VAT exactly', function (): void {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);

    for ($i = 0; $i < 200; $i++) {
        $quantity = fuzzQuantity(0, 999_000);
        $unitPrice = fuzzMoney(0, 9_999_999);

        $item = new InvoiceItem(['quantity' => $quantity, 'unit_price' => $unitPrice, 'vat_rate' => '0']);
        $item->recalculate();

        $message = "seed={$seed} iteration={$i} qty={$quantity} price={$unitPrice}";

        expect((float) $item->total_excl_vat)->toBeGreaterThanOrEqual(0.0, $message)
            ->and((float) $item->vat_amount)->toBe(0.0, $message)
            ->and((float) $item->total_incl_vat)->toBe((float) $item->total_excl_vat, $message);
    }
});

it('negates cleanly and symmetrically for a negated quantity, the way a credit note line does', function (): void {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);

    for ($i = 0; $i < 200; $i++) {
        $quantity = fuzzQuantity(1, 999_000);
        $unitPrice = fuzzMoney(1, 9_999_999);
        $vatRate = fuzzVatRate();

        $positive = new InvoiceItem(['quantity' => $quantity, 'unit_price' => $unitPrice, 'vat_rate' => $vatRate]);
        $positive->recalculate();

        $negative = new InvoiceItem([
            'quantity' => (string) Decimal::of($quantity)->negated(),
            'unit_price' => $unitPrice,
            'vat_rate' => $vatRate,
        ]);
        $negative->recalculate();

        $message = "seed={$seed} iteration={$i} qty={$quantity} price={$unitPrice} rate={$vatRate}";

        expect(Decimal::of($negative->total_excl_vat)->isEqualTo(Decimal::of($positive->total_excl_vat)->negated()))->toBeTrue($message)
            ->and(Decimal::of($negative->vat_amount)->isEqualTo(Decimal::of($positive->vat_amount)->negated()))->toBeTrue($message)
            ->and(Decimal::of($negative->total_incl_vat)->isEqualTo(Decimal::of($positive->total_incl_vat)->negated()))->toBeTrue($message);
    }
});

it('sums subtotal as the exact sum of item total_excl_vat, regardless of discount or VAT-rate mix', function (): void {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);
    $calculator = new VatRecapCalculator;

    for ($i = 0; $i < 300; $i++) {
        $items = fuzzUnsavedItems(mt_rand(1, 6));

        $expected = Decimal::money(Decimal::sum(array_map(
            static fn (InvoiceItem $item): string => (string) $item->total_excl_vat,
            $items,
        )));

        $message = "seed={$seed} iteration={$i} items=".count($items);

        // Compare float-to-float (both cast the same way subtotalFromItems()
        // itself casts) rather than re-parsing the float through Decimal::of()
        // — that would recover binary-float noise the (float) cast already
        // introduced and produce a false mismatch unrelated to the money math.
        expect($calculator->subtotalFromItems($items))->toBe((float) $expected, $message);
    }
});

it('returns zero discount amount when discount_percent is null, regardless of items', function (): void {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);
    $calculator = new VatRecapCalculator;

    for ($i = 0; $i < 100; $i++) {
        $items = fuzzUnsavedItems(mt_rand(1, 6));

        expect($calculator->discountAmountFromItems($items, null))->toBe(0.0, "seed={$seed} iteration={$i}");
    }
});

it('keeps header total (subtotal-discount+vat) exactly equal to the sum of recap row totals, even with a discount across multiple VAT rates', function (): void {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);
    $calculator = new VatRecapCalculator;
    $catalog = ['0', '5', '10', '12', '21', '23'];

    for ($i = 0; $i < 300; $i++) {
        $rateCount = min(mt_rand(1, 4), count($catalog));
        $shuffled = $catalog;
        shuffle($shuffled);
        $rates = array_slice($shuffled, 0, $rateCount);

        $items = [];
        foreach ($rates as $rate) {
            foreach (range(1, mt_rand(1, 3)) as $ignored) {
                $item = new InvoiceItem([
                    'quantity' => fuzzQuantity(1, 999_000),
                    'unit_price' => fuzzMoney(0, 999_999),
                    'vat_rate' => $rate,
                ]);
                $item->recalculate();
                $items[] = $item;
            }
        }

        $discount = fuzzDiscountPercent();

        $subtotal = $calculator->subtotalFromItems($items);
        $discountAmount = $calculator->discountAmountFromItems($items, $discount);
        $vatAmount = $calculator->vatAmountFromItems($items, $discount);

        // Plain float round(), the same way VatReportService itself
        // accumulates these already-rounded figures — going through
        // Decimal::of() on a float here would recover binary-float noise the
        // (float) casts in VatRecapCalculator already introduced and produce
        // a false mismatch unrelated to the money math (see
        // subtotalFromItems' test above for the same pitfall).
        $headerTotal = round($subtotal - $discountAmount + $vatAmount, 2);

        $rows = $calculator->recapFromItems($items, $discount);
        $rowTotalSum = round(array_sum(array_map(static fn ($row): float => $row->total, $rows)), 2);

        $message = "seed={$seed} iteration={$i} discount=".($discount ?? 'null').' rates='.implode(',', $rates);

        expect($headerTotal)->toBe($rowTotalSum, $message);
    }
});

it('keeps the per-rate VAT bucket within a small, bounded rounding distance of the naive per-item VAT sum', function (): void {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);
    $calculator = new VatRecapCalculator;
    $catalog = ['0', '5', '10', '12', '21', '23'];

    for ($i = 0; $i < 300; $i++) {
        $rateCount = min(mt_rand(1, 4), count($catalog));
        $shuffled = $catalog;
        shuffle($shuffled);
        $rates = array_slice($shuffled, 0, $rateCount);

        $items = [];
        foreach ($rates as $rate) {
            foreach (range(1, mt_rand(1, 3)) as $ignored) {
                $item = new InvoiceItem([
                    'quantity' => fuzzQuantity(1, 999_000),
                    'unit_price' => fuzzMoney(0, 999_999),
                    'vat_rate' => $rate,
                ]);
                $item->recalculate();
                $items[] = $item;
            }
        }

        $naiveVatSum = Decimal::money(Decimal::sum(array_map(
            static fn (InvoiceItem $item): string => (string) $item->vat_amount,
            $items,
        )));
        $bucketVat = $calculator->vatAmountFromItems($items, null);

        $driftCents = abs(((float) $bucketVat - (float) $naiveVatSum) * 100);
        $bound = (count($items) + 1) * 1.0; // generous: at most ~1 cent per item, documenting the known aggregate-vs-distributed rounding noise

        $message = "seed={$seed} iteration={$i} items=".count($items).' rates='.implode(',', $rates).
            " bucketVat={$bucketVat} naiveVatSum={$naiveVatSum} driftCents={$driftCents}";

        expect($driftCents)->toBeLessThanOrEqual($bound, $message);
    }
});

it('never flips sign or breaks monotonicity when converting a positive amount by a positive rate', function (): void {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);

    for ($i = 0; $i < 200; $i++) {
        $smaller = fuzzMoney(100, 500_000);
        $extraCents = mt_rand(0, 500_000);
        $larger = Decimal::money(Decimal::of($smaller)->plus(Decimal::of($extraCents / 100)));
        $rate = fuzzMoney(1, 5_000);

        $convertedSmaller = Decimal::money(Decimal::of($smaller)->multipliedBy(Decimal::of($rate)));
        $convertedLarger = Decimal::money(Decimal::of($larger)->multipliedBy(Decimal::of($rate)));

        $message = "seed={$seed} iteration={$i} smaller={$smaller} larger={$larger} rate={$rate}";

        expect((float) $convertedSmaller)->toBeGreaterThan(0.0, $message)
            ->and(Decimal::of($convertedLarger)->isGreaterThanOrEqualTo(Decimal::of($convertedSmaller)))->toBeTrue($message);
    }
});

it('keeps invoice.total exactly equal to subtotal minus discount plus VAT, idempotently, via recalculateTotals()', function (): void {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);

    $user = createUser(['invoice_prefix' => 'FA']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    for ($i = 0; $i < 40; $i++) {
        $invoice = Invoice::factory()->draft()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'currency' => 'EUR',
            'exchange_rate_snapshot' => null,
            'discount_percent' => fuzzDiscountPercent(),
        ]);

        foreach (range(1, mt_rand(1, 5)) as $sortOrder) {
            $invoice->items()->create([
                'description' => 'Fuzz item',
                'quantity' => fuzzQuantity(1, 999_000),
                'unit' => 'ks',
                'unit_price' => fuzzMoney(0, 999_999),
                'vat_rate' => fuzzVatRate(),
                'vat_amount' => 0,
                'total_excl_vat' => 0,
                'total_incl_vat' => 0,
                'sort_order' => $sortOrder,
            ])->recalculate()->save();
        }

        $invoice->refresh()->recalculateTotals()->save();

        $expectedTotal = Decimal::money(
            Decimal::of($invoice->subtotal)->minus(Decimal::of($invoice->discount_amount))->plus(Decimal::of($invoice->vat_amount)),
        );

        $message = "seed={$seed} iteration={$i} invoice={$invoice->id}";

        expect(Decimal::of($invoice->total)->isEqualTo(Decimal::of($expectedTotal)))->toBeTrue($message);

        $before = [(string) $invoice->subtotal, (string) $invoice->discount_amount, (string) $invoice->vat_amount, (string) $invoice->total];
        $invoice->recalculateTotals()->save();
        $after = [(string) $invoice->subtotal, (string) $invoice->discount_amount, (string) $invoice->vat_amount, (string) $invoice->total];

        expect($after)->toBe($before, "recalculateTotals() is not idempotent: {$message}");
    }
});

it('never doubles the base when settling a paid proforma into an invoice', function (): void {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);

    $user = createUser(['invoice_prefix' => 'FA', 'vat_status' => 'payer']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    for ($i = 0; $i < 20; $i++) {
        $proforma = Invoice::factory()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'type' => 'proforma',
            'status' => 'sent',
            'currency' => 'EUR',
            'discount_percent' => fuzzDiscountPercent(),
        ]);

        $itemCount = mt_rand(1, 4);
        foreach (range(1, $itemCount) as $sortOrder) {
            $proforma->items()->create([
                'description' => 'Fuzz proforma item',
                'quantity' => fuzzQuantity(1, 999_000),
                'unit' => 'ks',
                'unit_price' => fuzzMoney(100, 999_999),
                'vat_rate' => fuzzVatRate(),
                'vat_amount' => 0,
                'total_excl_vat' => 0,
                'total_incl_vat' => 0,
                'sort_order' => $sortOrder,
            ])->recalculate()->save();
        }

        $proforma->refresh()->recalculateTotals()->save();
        $proforma->payments()->create(['amount' => $proforma->total, 'paid_at' => today(), 'method' => 'bank_transfer']);
        $proforma->update(['status' => 'paid']);
        $proforma->refresh()->loadMissing(['items', 'payments']);

        $message = "seed={$seed} iteration={$i} proforma={$proforma->id}";

        $response = $this->actingAs($user)->postJson("/api/v1/invoices/{$proforma->id}/settle");
        $response->assertCreated();

        /** @var Invoice $settled */
        $settled = Invoice::withoutGlobalScope('user')->with(['items', 'payments'])->findOrFail($response->json('data.id'));

        expect(Decimal::of($settled->subtotal)->isEqualTo(Decimal::of($proforma->subtotal)))->toBeTrue($message)
            ->and(Decimal::of($settled->total)->isEqualTo(Decimal::of($proforma->total)))->toBeTrue($message)
            ->and($settled->items)->toHaveCount($itemCount, $message)
            ->and(round((float) $settled->payments->sum('amount'), 2))->toBe(round((float) $settled->total, 2), $message);
    }
});

it('mirrors a credit note against its original invoice exactly: subtotal, VAT and total all negate', function (): void {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);

    $user = createUser(['invoice_prefix' => 'FA']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    for ($i = 0; $i < 20; $i++) {
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'type' => 'invoice',
            'status' => 'sent',
            'currency' => 'EUR',
            'discount_percent' => fuzzDiscountPercent(),
        ]);

        foreach (range(1, mt_rand(1, 4)) as $sortOrder) {
            $invoice->items()->create([
                'description' => 'Fuzz invoice item',
                'quantity' => fuzzQuantity(1, 999_000),
                'unit' => 'ks',
                'unit_price' => fuzzMoney(0, 999_999),
                'vat_rate' => fuzzVatRate(),
                'vat_amount' => 0,
                'total_excl_vat' => 0,
                'total_incl_vat' => 0,
                'sort_order' => $sortOrder,
            ])->recalculate()->save();
        }

        $invoice->refresh()->recalculateTotals()->save();

        $message = "seed={$seed} iteration={$i} invoice={$invoice->id}";

        $response = $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/corrective", ['type' => 'credit_note']);
        $response->assertCreated();

        /** @var Invoice $corrective */
        $corrective = Invoice::withoutGlobalScope('user')->findOrFail($response->json('data.id'));

        expect(Decimal::of($corrective->subtotal)->isEqualTo(Decimal::of($invoice->subtotal)->negated()))->toBeTrue($message)
            ->and(Decimal::of($corrective->vat_amount)->isEqualTo(Decimal::of($invoice->vat_amount)->negated()))->toBeTrue($message)
            ->and(Decimal::of($corrective->total)->isEqualTo(Decimal::of($invoice->total)->negated()))->toBeTrue($message);
    }
});
