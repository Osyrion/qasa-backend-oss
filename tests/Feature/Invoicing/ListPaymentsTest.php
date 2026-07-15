<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;

it('lists payments recorded against an invoice, ordered by paid_at', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'total' => 300,
    ]);

    $later = InvoicePayment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 100,
        'paid_at' => today(),
    ]);
    $earlier = InvoicePayment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 200,
        'paid_at' => today()->subDays(5),
    ]);

    $response = $this->actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}/payments");

    $response->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.id', $earlier->id)
        ->assertJsonPath('1.id', $later->id);
});

it('does not let a user list payments on another account invoice', function (): void {
    $victim = createUser();
    $victimClient = Client::factory()->create(['user_id' => $victim->id]);
    $victimInvoice = Invoice::factory()->sent()->create([
        'user_id' => $victim->id,
        'client_id' => $victimClient->id,
    ]);

    $attacker = createUser();

    $this->actingAs($attacker)
        ->getJson("/api/v1/invoices/{$victimInvoice->id}/payments")
        ->assertNotFound();
});
