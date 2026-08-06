<?php

declare(strict_types=1);

use App\Modules\Invoicing\Application\Actions\DeletePaymentAction;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use Illuminate\Support\Carbon;

/**
 * RecordPaymentAction re-reads the invoice under a row lock before deciding
 * anything, with a comment spelling out why: it is reached from the Stripe
 * webhook, the bank matcher and a person clicking "mark paid", so the
 * instance handed to it can already be stale.
 *
 * DeletePaymentAction decides the same thing — whether the invoice's paid
 * status still holds once the payment sum changes — from the instance route
 * model binding read at the top of the request, with no lock and no re-read.
 * Its balance() re-queries, so the *amount* is fresh; the `isPaid()` check
 * gating the revert is not. An invoice marked paid by a concurrent commit
 * after binding therefore keeps that status when the payment covering it is
 * removed.
 *
 * Reproduced deterministically below by moving the row underneath a loaded
 * instance, which is what a concurrent RecordPaymentAction commit does. The
 * pairing is realistic: SyncBankConnectionsCommand auto-applies matched
 * payments (Provenance::AutoMatched) while a person is deleting a
 * mis-recorded one.
 */
it('reverts a paid invoice whose status changed after the instance was loaded', function (): void {
    $owner = createUser();

    $invoice = Invoice::factory()->sent()->for($owner)->create(['total' => '100.00']);

    $mistake = $invoice->payments()->create([
        'amount' => '60.00',
        'paid_at' => Carbon::now()->toDateString(),
        'method' => 'bank_transfer',
    ]);

    // The instance the action will be handed — bound, like a controller's,
    // before anything else happens.
    $bound = Invoice::query()->findOrFail($invoice->id);

    // A concurrent payment lands and covers the rest: the row is now paid,
    // while $bound still remembers "sent".
    $invoice->payments()->create([
        'amount' => '40.00',
        'paid_at' => Carbon::now()->toDateString(),
        'method' => 'bank_transfer',
    ]);
    Invoice::query()->whereKey($invoice->id)->update(['status' => InvoiceStatus::Paid->value]);

    app(DeletePaymentAction::class)->execute($bound, $mistake);

    $fresh = Invoice::query()->findOrFail($invoice->id);

    // 40 of 100 paid — the invoice is not settled and must not claim to be.
    expect($fresh->balance())->toBe(60.0)
        ->and($fresh->statusEnum())->not->toBe(InvoiceStatus::Paid);
});

it('still leaves a genuinely covered invoice paid', function (): void {
    $owner = createUser();

    $invoice = Invoice::factory()->sent()->for($owner)->create(['total' => '100.00']);

    $keep = $invoice->payments()->create([
        'amount' => '100.00',
        'paid_at' => Carbon::now()->toDateString(),
        'method' => 'bank_transfer',
    ]);

    $extra = $invoice->payments()->create([
        'amount' => '25.00',
        'paid_at' => Carbon::now()->toDateString(),
        'method' => 'bank_transfer',
    ]);

    Invoice::query()->whereKey($invoice->id)->update(['status' => InvoiceStatus::Paid->value]);

    // Removing the duplicate overpayment still leaves the invoice covered.
    app(DeletePaymentAction::class)->execute(Invoice::query()->findOrFail($invoice->id), $extra);

    $fresh = Invoice::query()->findOrFail($invoice->id);

    expect($fresh->statusEnum())->toBe(InvoiceStatus::Paid)
        ->and($fresh->balance())->toBe(0.0)
        ->and(InvoicePayment::query()->whereKey($keep->id)->exists())->toBeTrue();
});
