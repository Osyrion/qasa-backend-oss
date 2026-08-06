<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Actions\RecordPaymentAction;
use App\Modules\Invoicing\Application\DTOs\PaymentData;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Events\InvoicePaid;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Shared\Enums\PaymentMethod;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Event;

/**
 * RecordPaymentAction is reached from three places at once — the Stripe
 * webhook, the bank statement matcher, and a person clicking "mark paid" —
 * so the model it is handed can be stale by the time the transaction opens.
 * UpdateInvoiceStatusAction already re-reads under a row lock for exactly
 * this reason; this one did not.
 *
 * Real threads are out of reach inside RefreshDatabase, so these commit the
 * competing write first and hand the action the model from before it, which
 * is the same state a concurrent request would have been holding.
 */
function invoiceForPayment(): Invoice
{
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    return Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => InvoiceStatus::Sent->value,
        'subtotal' => 100,
        'vat_amount' => 0,
        'total' => 100,
    ]);
}

function paymentOf(float $amount): PaymentData
{
    return PaymentData::from([
        'amount' => $amount,
        'paid_at' => now()->toDateString(),
        'method' => PaymentMethod::BankTransfer->value,
    ]);
}

it('does not fire InvoicePaid twice when another request settled the invoice first', function (): void {
    $stale = invoiceForPayment();

    // Another request settles it in full and commits.
    InvoicePayment::factory()->create(['invoice_id' => $stale->id, 'amount' => 100]);
    Invoice::query()->whereKey($stale->id)->update(['status' => InvoiceStatus::Paid->value]);

    Event::fake([InvoicePaid::class]);

    // $stale still believes the invoice is 'sent' — the state this request
    // read before the other one committed.
    app(RecordPaymentAction::class)->execute($stale, paymentOf(0.01));

    // Without re-reading under a lock, the stale 'sent' passes the
    // `! isPaid()` guard and the customer gets a second "payment received"
    // e-mail for an invoice that was already settled.
    Event::assertNotDispatched(InvoicePaid::class);
});

it('refuses a payment against an invoice another request has cancelled', function (): void {
    $stale = invoiceForPayment();

    Invoice::query()->whereKey($stale->id)->update(['status' => InvoiceStatus::Cancelled->value]);

    expect(fn () => app(RecordPaymentAction::class)->execute($stale, paymentOf(100)))
        ->toThrow(DomainException::class);

    expect(InvoicePayment::query()->where('invoice_id', $stale->id)->count())->toBe(0);
});

it('still settles an invoice that is genuinely covered', function (): void {
    $invoice = invoiceForPayment();

    Event::fake([InvoicePaid::class]);

    app(RecordPaymentAction::class)->execute($invoice, paymentOf(100));

    Event::assertDispatched(InvoicePaid::class);
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid->value);
});
