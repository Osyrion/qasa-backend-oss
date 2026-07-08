<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;

it('does not let a user delete another account draft invoice', function (): void {
    $victim = createUser();
    $victimClient = Client::factory()->create(['user_id' => $victim->id]);

    $victimInvoice = Invoice::factory()->draft()->create([
        'user_id' => $victim->id,
        'client_id' => $victimClient->id,
    ]);

    $attacker = createUser();

    // The HasUserScope global scope hides foreign invoices from route binding.
    $this->actingAs($attacker)
        ->deleteJson("/api/v1/invoices/{$victimInvoice->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('invoices', ['id' => $victimInvoice->id, 'deleted_at' => null]);
});

it('does not let a user remove an item belonging to another account invoice', function (): void {
    $victim = createUser();
    $victimClient = Client::factory()->create(['user_id' => $victim->id]);

    $victimInvoice = Invoice::factory()->draft()->create([
        'user_id' => $victim->id,
        'client_id' => $victimClient->id,
    ]);

    $victimItem = $victimInvoice->items()->create([
        'description' => 'Práce',
        'quantity' => 1,
        'unit' => 'ks',
        'unit_price' => 100,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total_excl_vat' => 100,
        'total_incl_vat' => 100,
        'sort_order' => 0,
    ]);

    $attacker = createUser();
    $attackerClient = Client::factory()->create(['user_id' => $attacker->id]);

    $attackerInvoice = Invoice::factory()->draft()->create([
        'user_id' => $attacker->id,
        'client_id' => $attackerClient->id,
    ]);

    // Attacker authorizes on their own invoice but references the victim's item.
    $this->actingAs($attacker)
        ->deleteJson("/api/v1/invoices/{$attackerInvoice->id}/items/{$victimItem->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('invoice_items', ['id' => $victimItem->id]);
});

it('still lets a user delete their own draft invoice', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $invoice = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/invoices/{$invoice->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);
});
