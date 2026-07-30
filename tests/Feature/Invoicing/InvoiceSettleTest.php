<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;

/**
 * @param  array<string, mixed>  $overrides
 */
function paidProforma(User $user, Client $client, array $overrides = []): Invoice
{
    $proforma = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'proforma',
        'status' => 'sent',
        'currency' => 'EUR',
        'discount_percent' => null,
        'taxable_supply_at' => null,
        ...$overrides,
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

    $proforma->update(['status' => 'paid']);

    return $proforma->refresh()->loadMissing(['items', 'payments']);
}

it('settles a fully paid proforma into an ordinary paid invoice', function (): void {
    $user = createUser(['invoice_prefix' => 'FA', 'vat_status' => 'payer']);
    $client = Client::factory()->create(['user_id' => $user->id]);
    $proforma = paidProforma($user, $client);

    $response = $this->actingAs($user)->postJson("/api/v1/invoices/{$proforma->id}/settle");

    $response->assertCreated();

    expect($response->json('data.type'))->toBe('invoice')
        ->and($response->json('data.status'))->toBe('paid')
        ->and($response->json('data.related_invoice_id'))->toBe($proforma->id)
        ->and((float) $response->json('data.balance'))->toBe(0.0)
        ->and((float) $response->json('data.total'))->toBe(1200.0)
        ->and($response->json('data.taxable_supply_at'))->toBe(today()->subDays(2)->toDateString())
        ->and($response->json('data.items'))->toHaveCount(1)
        ->and((float) $response->json('data.items.0.unit_price'))->toBe(1000.0);

    expect($proforma->refresh()->settled_invoice_id)->toBe($response->json('data.id'))
        ->and($proforma->type->value)->toBe('proforma');
});

it('rejects settling a document that is not a proforma', function (): void {
    $user = createUser(['invoice_prefix' => 'FA', 'vat_status' => 'payer']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'invoice',
        'status' => 'paid',
    ]);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/settle")
        ->assertUnprocessable();
});

it('rejects settling a proforma that is not fully paid', function (): void {
    $user = createUser(['invoice_prefix' => 'FA', 'vat_status' => 'payer']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $proforma = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'proforma',
        'status' => 'sent',
    ]);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$proforma->id}/settle")
        ->assertUnprocessable();
});

it('rejects settling an already settled proforma', function (): void {
    $user = createUser(['invoice_prefix' => 'FA', 'vat_status' => 'payer']);
    $client = Client::factory()->create(['user_id' => $user->id]);
    $proforma = paidProforma($user, $client);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$proforma->id}/settle")->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$proforma->id}/settle")->assertUnprocessable();

    expect(Invoice::withoutGlobalScope('user')->where('related_invoice_id', $proforma->id)->count())->toBe(1);
});

it('does not let a user settle another account proforma', function (): void {
    $victim = createUser(['invoice_prefix' => 'FA']);
    $victimClient = Client::factory()->create(['user_id' => $victim->id]);
    $proforma = paidProforma($victim, $victimClient);

    $attacker = createUser(['invoice_prefix' => 'FA']);

    $this->actingAs($attacker)->postJson("/api/v1/invoices/{$proforma->id}/settle")
        ->assertNotFound();
});
