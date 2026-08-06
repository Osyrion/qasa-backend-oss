<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Actions\SettleProformaAction;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * Settling a proforma mints a real, numbered, issued-and-paid invoice and
 * copies the deposit payments onto it. Doing it twice therefore does not
 * produce a harmless duplicate row — it produces a second numbered document,
 * with its own VAT, in the ledger and the VAT return.
 *
 * The three guards (type, paid, not already settled) all read the instance
 * the caller passed in, outside the transaction and with no row lock, so two
 * settles in flight both see `settled_invoice_id === null` and both proceed.
 * The proforma's own column is a last-write-wins record of only one of them;
 * nothing at the database level objects either, since settled_invoice_id and
 * related_invoice_id carry plain indexes (credit notes share the latter, so a
 * blanket unique index is not available).
 *
 * Two triggers can reach it at once without anything exotic: the settle
 * endpoint carries no idempotency key, so a double submit is enough, and
 * AutoSettleProforma settles synchronously on InvoicePaid — a click landing
 * as the bank sync records the final payment races the listener.
 *
 * Reproduced below with a stale instance, which is what the second request
 * holds.
 */
/**
 * Reuses paidProforma() from InvoiceSettleTest — same fixture the ordinary
 * settle path is proven against, so this only varies the concurrency.
 */
/**
 * Self-contained on purpose. InvoiceSettleTest has an equivalent
 * paidProforma() helper, but Pest only defines it when that file is loaded
 * too — borrowing it would make this file unrunnable on its own.
 *
 * @return array{0: Invoice, 1: User}
 */
function racedProforma(): array
{
    $user = createUser(['invoice_prefix' => 'FA', 'vat_status' => 'payer']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $proforma = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => InvoiceType::Proforma->value,
        'status' => InvoiceStatus::Sent->value,
        'currency' => 'EUR',
        'discount_percent' => null,
        'taxable_supply_at' => null,
    ]);

    $proforma->items()->create([
        'description' => 'Záloha na práce',
        'quantity' => 1,
        'unit' => 'ks',
        'unit_price' => 1000,
        'vat_rate' => 20,
        'vat_amount' => 200,
        'total_excl_vat' => 1000,
        'total_incl_vat' => 1200,
        'sort_order' => 0,
    ]);

    $proforma->refresh()->recalculateTotals()->save();

    $proforma->payments()->create([
        'amount' => 1200,
        'paid_at' => today()->subDays(2),
        'method' => 'bank_transfer',
    ]);

    $proforma->update(['status' => InvoiceStatus::Paid->value]);

    return [$proforma->refresh()->loadMissing(['items', 'payments']), $user];
}

it('settles a proforma exactly once when two requests hold it at the same time', function (): void {
    [$proforma, $user] = racedProforma();

    // Both requests bound the row before either ran — the second one's
    // instance still says "not settled" after the first commits.
    $first = Invoice::query()->with(['items', 'payments'])->findOrFail($proforma->id);
    $second = Invoice::query()->with(['items', 'payments'])->findOrFail($proforma->id);

    $action = app(SettleProformaAction::class);

    $action->execute($first, $user);

    expect(fn () => $action->execute($second, $user))->toThrow(DomainException::class);

    $settlements = Invoice::query()
        ->where('related_invoice_id', $proforma->id)
        ->where('type', InvoiceType::Invoice->value)
        ->get();

    expect($settlements)->toHaveCount(1);
});

it('still settles a proforma the ordinary way', function (): void {
    [$proforma, $user] = racedProforma();

    $invoice = app(SettleProformaAction::class)->execute($proforma, $user);

    expect($invoice->type)->toBe(InvoiceType::Invoice)
        ->and($invoice->invoice_number)->not->toBeNull()
        ->and($invoice->statusEnum())->toBe(InvoiceStatus::Paid)
        ->and($invoice->balance())->toBe(0.0)
        ->and($proforma->fresh()?->settled_invoice_id)->toBe($invoice->id);
});
