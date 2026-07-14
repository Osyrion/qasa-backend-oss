<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;

it('updates a draft invoice and recalculates totals with the discount', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $invoice = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'currency' => 'EUR',
        'discount_percent' => null,
    ]);

    $invoice->items()->create([
        'description' => 'Práce',
        'quantity' => 1,
        'unit' => 'ks',
        'unit_price' => 100,
        'vat_rate' => 20,
        'vat_amount' => 20,
        'total_excl_vat' => 100,
        'total_incl_vat' => 120,
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($user)->patchJson("/api/v1/invoices/{$invoice->id}", [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(30)->toDateString(),
        'currency' => 'EUR',
        'discount_percent' => 50,
        'note' => 'Upravené',
        'note_above' => 'Fakturujeme Vám za jún',
    ]);

    $response->assertOk();

    expect((float) $response->json('discount_percent'))->toBe(50.0)
        ->and((float) $response->json('discount_amount'))->toBe(50.0)
        ->and((float) $response->json('total'))->toBe(60.0) // 100 − 50 + 10 VAT
        ->and($response->json('note'))->toBe('Upravené')
        ->and($response->json('note_above'))->toBe('Fakturujeme Vám za jún');
});

it('rejects updating a sent invoice', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => 'sent',
        'currency' => 'EUR',
    ]);

    $this->actingAs($user)->patchJson("/api/v1/invoices/{$invoice->id}", [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(30)->toDateString(),
        'currency' => 'EUR',
    ])->assertForbidden();
});
