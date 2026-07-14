<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;

/** @param array<string, mixed> $attributes */
function recapInvoice(array $attributes = []): Invoice
{
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    return Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'currency' => 'EUR',
        'exchange_rate_snapshot' => null,
        'discount_percent' => null,
        ...$attributes,
    ]);
}

function addRecapItem(Invoice $invoice, float $quantity, float $unitPrice, float $vatRate): void
{
    $item = $invoice->items()->create([
        'description' => 'Item',
        'quantity' => $quantity,
        'unit' => 'ks',
        'unit_price' => $unitPrice,
        'vat_rate' => $vatRate,
        'vat_amount' => 0,
        'total_excl_vat' => 0,
        'total_incl_vat' => 0,
        'sort_order' => 0,
    ]);
    $item->recalculate()->save();
}

it('groups items into per-rate buckets', function (): void {
    $invoice = recapInvoice();
    addRecapItem($invoice, 1, 100, 21);
    addRecapItem($invoice, 1, 50, 21);
    addRecapItem($invoice, 1, 200, 0);

    $rows = new VatRecapCalculator()->recap($invoice->refresh());

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->rate)->toBe(0.0)
        ->and($rows[0]->base)->toBe(200.0)
        ->and($rows[0]->vat)->toBe(0.0)
        ->and($rows[1]->rate)->toBe(21.0)
        ->and($rows[1]->base)->toBe(150.0)
        ->and($rows[1]->vat)->toBe(31.5);
});

it('applies the invoice discount proportionally per bucket', function (): void {
    $invoice = recapInvoice(['discount_percent' => 10]);
    addRecapItem($invoice, 1, 100, 21);
    addRecapItem($invoice, 1, 200, 0);

    $calculator = new VatRecapCalculator;
    $rows = $calculator->recap($invoice->refresh());

    expect($rows[0]->base)->toBe(180.0)   // 200 − 10 %
        ->and($rows[1]->base)->toBe(90.0) // 100 − 10 %
        ->and($rows[1]->vat)->toBe(18.9)
        ->and($calculator->discountAmount($invoice))->toBe(30.0);
});

it('recalculates invoice totals from the recap including discount', function (): void {
    $invoice = recapInvoice(['discount_percent' => 10]);
    addRecapItem($invoice, 1, 100, 21);

    $invoice->refresh()->recalculateTotals()->save();

    expect((float) $invoice->subtotal)->toBe(100.0)
        ->and((float) $invoice->discount_amount)->toBe(10.0)
        ->and((float) $invoice->vat_amount)->toBe(18.9)
        ->and((float) $invoice->total)->toBe(108.9); // 100 − 10 + 18.9
});

it('converts the recap to CZK via the rate snapshot', function (): void {
    $invoice = recapInvoice(['exchange_rate_snapshot' => 25.0]);
    addRecapItem($invoice, 1, 100, 21);

    $czk = new VatRecapCalculator()->czkRecap($invoice->refresh());
    $row = $czk[0] ?? null;

    expect($czk)->toHaveCount(1)
        ->and($row?->base)->toBe(2500.0)
        ->and($row?->vat)->toBe(525.0)
        ->and($row?->total)->toBe(3025.0);
});

it('returns no CZK recap for CZK invoices or without a snapshot', function (): void {
    $czkInvoice = recapInvoice(['currency' => 'CZK', 'exchange_rate_snapshot' => 25.0]);
    $noRate = recapInvoice();

    $calculator = new VatRecapCalculator;

    expect($calculator->czkRecap($czkInvoice))->toBeNull()
        ->and($calculator->czkRecap($noRate))->toBeNull();
});

// ── Item-based core (shared by Invoice and Quote) ────────────────────────────

/** @param array<string, mixed> $attributes */
function recapQuote(array $attributes = []): Quote
{
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    return Quote::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'currency' => 'EUR',
        'discount_percent' => null,
        ...$attributes,
    ]);
}

function addRecapQuoteItem(Quote $quote, float $quantity, float $unitPrice, float $vatRate): void
{
    $item = $quote->items()->create([
        'description' => 'Item',
        'quantity' => $quantity,
        'unit' => 'ks',
        'unit_price' => $unitPrice,
        'vat_rate' => $vatRate,
        'vat_amount' => 0,
        'total_excl_vat' => 0,
        'total_incl_vat' => 0,
        'sort_order' => 0,
    ]);
    $item->recalculate()->save();
}

it('computes the same per-rate buckets for a quote as for an invoice', function (): void {
    $quote = recapQuote(['discount_percent' => 10]);
    addRecapQuoteItem($quote, 1, 100, 21);
    addRecapQuoteItem($quote, 1, 200, 0);

    $calculator = new VatRecapCalculator;
    $rows = $calculator->recapForQuote($quote->refresh());

    expect($rows[0]->base)->toBe(180.0)
        ->and($rows[1]->base)->toBe(90.0)
        ->and($rows[1]->vat)->toBe(18.9)
        ->and($calculator->discountAmountForQuote($quote))->toBe(30.0)
        ->and($calculator->subtotalForQuote($quote))->toBe(300.0)
        ->and($calculator->vatAmountForQuote($quote))->toBe(18.9);
});

it('recalculates quote totals via the shared item-based core', function (): void {
    $quote = recapQuote(['discount_percent' => 10]);
    addRecapQuoteItem($quote, 1, 100, 21);

    $quote->refresh()->recalculateTotals()->save();

    expect((float) $quote->subtotal)->toBe(100.0)
        ->and((float) $quote->discount_amount)->toBe(10.0)
        ->and((float) $quote->vat_amount)->toBe(18.9)
        ->and((float) $quote->total)->toBe(108.9);
});

it('recapFromItems with no discount matches the plain per-rate buckets', function (): void {
    $quote = recapQuote();
    addRecapQuoteItem($quote, 2, 100, 20);

    $calculator = new VatRecapCalculator;
    $rows = $calculator->recapFromItems($quote->refresh()->items, null);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->base)->toBe(200.0)
        ->and($rows[0]->vat)->toBe(40.0);
});
