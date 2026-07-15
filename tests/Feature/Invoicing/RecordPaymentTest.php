<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;

it('tracks a partial payment without flipping the invoice to paid', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'subtotal' => 200,
        'vat_amount' => 40,
        'total' => 240,
    ]);

    $response = $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", [
        'amount' => 100,
        'paid_at' => today()->toDateString(),
        'method' => 'bank_transfer',
    ]);

    $response->assertCreated()->assertJsonPath('amount', 100);

    $show = $this->actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}");
    $show->assertOk()
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('payment_status', 'partial')
        ->assertJsonPath('balance', 140);
});

it('flips the invoice to paid once payments fully cover the total', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'subtotal' => 200,
        'vat_amount' => 40,
        'total' => 240,
    ]);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", [
        'amount' => 240,
        'paid_at' => today()->toDateString(),
    ])->assertCreated();

    $show = $this->actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}");
    $show->assertOk()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('payment_status', 'paid')
        ->assertJsonPath('balance', 0);
});

it('reports an overpayment without erroring', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'subtotal' => 100,
        'vat_amount' => 0,
        'total' => 100,
    ]);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", [
        'amount' => 150,
        'paid_at' => today()->toDateString(),
    ])->assertCreated();

    $this->actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}")
        ->assertOk()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('payment_status', 'overpaid');
});

it('refuses to record a payment on a draft', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", [
        'amount' => 100,
        'paid_at' => today()->toDateString(),
    ])->assertUnprocessable();
});

it('reopens the invoice when a payment that covered it is deleted', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'subtotal' => 100,
        'vat_amount' => 0,
        'total' => 100,
    ]);

    $paymentId = $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", [
        'amount' => 100,
        'paid_at' => today()->toDateString(),
    ])->assertCreated()->json('id');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);

    $this->actingAs($user)
        ->deleteJson("/api/v1/invoices/{$invoice->id}/payments/{$paymentId}")
        ->assertNoContent();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Sent);
});

it('does not let a user record a payment on another account invoice', function (): void {
    $victim = createUser();
    $victimClient = Client::factory()->create(['user_id' => $victim->id]);
    $victimInvoice = Invoice::factory()->sent()->create([
        'user_id' => $victim->id,
        'client_id' => $victimClient->id,
    ]);

    $attacker = createUser();

    $this->actingAs($attacker)->postJson("/api/v1/invoices/{$victimInvoice->id}/payments", [
        'amount' => 100,
        'paid_at' => today()->toDateString(),
    ])->assertNotFound();
});
