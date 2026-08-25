<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Shared\Domain\Contracts\Account;

/**
 * Money columns are decimal(_, 2), but the amount rules only asked for
 * `numeric` and `gt:0` — so a sub-cent amount passed validation and Postgres
 * quietly rounded it away on the way in. 0.001 became a payment of 0.00: a
 * record that says the invoice was paid something and moves no money.
 *
 * The rounding half of it is the same defect one digit up — 10.005 is not a
 * rejected input today, it is an 10.01 payment the client never made.
 */
/**
 * @return array{0: Account, 1: Invoice}
 */
function invoiceToPay(): array
{
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'subtotal' => 100,
        'vat_amount' => 0,
        'total' => 100,
    ]);

    return [$user, $invoice];
}

it('rejects a payment smaller than a cent instead of recording it as zero', function (): void {
    [$user, $invoice] = invoiceToPay();

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 0.001,
            'paid_at' => today()->toDateString(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');

    expect(InvoicePayment::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

it('rejects an amount with more precision than the column keeps', function (): void {
    [$user, $invoice] = invoiceToPay();

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 10.005,
            'paid_at' => today()->toDateString(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');
});

it('still accepts whole and two-decimal amounts', function (): void {
    [$user, $invoice] = invoiceToPay();

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 40,
            'paid_at' => today()->toDateString(),
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 60.55,
            'paid_at' => today()->toDateString(),
        ])
        ->assertCreated();
});
