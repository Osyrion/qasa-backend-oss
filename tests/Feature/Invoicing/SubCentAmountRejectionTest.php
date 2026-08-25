<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Orders\Domain\Models\Order;

/**
 * Money columns are decimal(x,2) and Postgres rounds silently on the way in,
 * so an amount the API accepts with more precision than that is money the
 * account never sees again: unit_price 0.004 × 1000 was accepted with 201 and
 * stored as a line worth 0.00.
 *
 * `decimal:0,2` on the payment amount closed the same hole in the payment
 * endpoints (2026-08-19); these are the rest of the surface — every input
 * that reaches a money, quantity or rate column. The rule takes the column's
 * own scale, so quantity keeps its three decimals and an exchange rate its
 * six.
 */
it('rejects a sub-cent unit price on an invoice item', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'description' => 'Sub-cent',
            'quantity' => 1000,
            'unit' => 'ks',
            'unit_price' => 0.004,
            'vat_rate' => 0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('unit_price');
});

it('rejects a quantity finer than the column keeps', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'description' => 'Sub-milli',
            'quantity' => 1.0004,
            'unit' => 'ks',
            'unit_price' => 100,
            'vat_rate' => 0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('quantity');
});

it('still accepts the precision the columns do keep', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'description' => 'Presne na cent',
            'quantity' => 1.001,
            'unit' => 'ks',
            'unit_price' => 0.01,
            'vat_rate' => 0,
        ])
        ->assertCreated();
});

it('rejects a sub-cent unit price on an order item', function (): void {
    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/orders/{$order->id}/items", [
            'type' => 'service',
            'description' => 'Sub-cent',
            'quantity' => 1000,
            'unit' => 'h',
            'unit_price' => 0.004,
            'vat_rate' => 20,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('unit_price');
});

it('rejects a sub-cent VAT base on a supplier invoice', function (): void {
    $user = createUser(['country' => 'SK', 'vat_status' => 'payer']);
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => 'SK']);

    $this->actingAs($user)
        ->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($client->id, [
            'vat_lines' => [
                ['vat_rate' => 23, 'base' => 100.004, 'vat_amount' => 23],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('vat_lines.0.base');
});
