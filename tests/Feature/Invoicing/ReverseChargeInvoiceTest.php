<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Support\Facades\Http;

function rcOwner(string $vatStatus, string $country = 'SK'): User
{
    $user = createUser(['country' => $country, 'vat_status' => $vatStatus]);
    app(VatRateSeederService::class)->seedFor($user);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rcInvoicePayload(Client $client, array $overrides = []): array
{
    return array_merge([
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ], $overrides);
}

it('applies domestic reverse charge for a payer with an opted-in SK client and forces item rates to 0%', function (): void {
    $user = rcOwner('payer');
    $client = Client::factory()->create([
        'user_id' => $user->id, 'country' => 'SK', 'reverse_charge_allowed' => true, 'vat_id' => 'SK2020202020',
    ]);

    $response = $this->actingAs($user)->postJson('/api/v1/invoices', rcInvoicePayload($client, ['reverse_charge' => true]));

    $response->assertCreated()
        ->assertJsonPath('reverse_charge', true)
        ->assertJsonPath('reverse_charge_mode', 'domestic');

    $item = $this->actingAs($user)->postJson("/api/v1/invoices/{$response->json('id')}/items", [
        'description' => 'Konzultácia', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 1000, 'vat_rate' => 23,
    ])->assertCreated();

    expect((float) $item->json('vat_rate'))->toBe(0.0)
        ->and((float) $item->json('vat_amount'))->toBe(0.0);
});

it('rejects domestic reverse charge for a client that has not opted in', function (): void {
    $user = rcOwner('payer');
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => 'SK', 'reverse_charge_allowed' => false]);

    $this->actingAs($user)
        ->postJson('/api/v1/invoices', rcInvoicePayload($client, ['reverse_charge' => true]))
        ->assertStatus(422);
});

it('auto-applies EU reverse charge for an identified person with a valid EU client', function (): void {
    $user = rcOwner('identified');
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => 'DE', 'vat_id' => 'DE123456789']);

    $response = $this->actingAs($user)->postJson('/api/v1/invoices', rcInvoicePayload($client));

    $response->assertCreated()
        ->assertJsonPath('reverse_charge', true)
        ->assertJsonPath('reverse_charge_mode', 'eu');
});

it('treats an identified person with a domestic client like a non-payer', function (): void {
    $user = rcOwner('identified');
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => 'SK']);

    $response = $this->actingAs($user)->postJson('/api/v1/invoices', rcInvoicePayload($client));

    $response->assertCreated()
        ->assertJsonPath('reverse_charge', false)
        ->assertJsonPath('reverse_charge_mode', null);
});

it('rejects a non-payer requesting reverse charge', function (): void {
    $user = rcOwner('non_payer');
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => 'SK', 'reverse_charge_allowed' => true]);

    $this->actingAs($user)
        ->postJson('/api/v1/invoices', rcInvoicePayload($client, ['reverse_charge' => true]))
        ->assertStatus(422);
});

it('nets a reverse-charged invoice total against the discount with zero VAT', function (): void {
    $user = rcOwner('payer');
    $client = Client::factory()->create([
        'user_id' => $user->id, 'country' => 'SK', 'reverse_charge_allowed' => true,
    ]);

    $created = $this->actingAs($user)->postJson('/api/v1/invoices', rcInvoicePayload($client, [
        'reverse_charge' => true,
        'discount_percent' => 10,
    ]))->assertCreated();

    $this->actingAs($user)->postJson("/api/v1/invoices/{$created->json('id')}/items", [
        'description' => 'Služby', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 1000, 'vat_rate' => 0,
    ])->assertCreated();

    $invoice = Invoice::withoutGlobalScope('user')->findOrFail($created->json('id'));

    expect((float) $invoice->subtotal)->toBe(1000.0)
        ->and((float) $invoice->discount_amount)->toBe(100.0)
        ->and((float) $invoice->vat_amount)->toBe(0.0)
        ->and((float) $invoice->total)->toBe(900.0);
});

/**
 * @return array{0: User, 1: Invoice}
 */
function euRcInvoiceReadyForIssue(object $test, ?string $vatVerifiedAt = null): array
{
    $user = rcOwner('payer');
    $client = Client::factory()->create([
        'user_id' => $user->id, 'country' => 'DE', 'vat_id' => 'DE123456789', 'vat_verified_at' => $vatVerifiedAt,
    ]);

    $created = $test->actingAs($user)->postJson('/api/v1/invoices', rcInvoicePayload($client))->assertCreated();

    $test->actingAs($user)->postJson("/api/v1/invoices/{$created->json('id')}/items", [
        'description' => 'Služby', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 1000, 'vat_rate' => 0,
    ])->assertCreated();

    $invoice = Invoice::withoutGlobalScope('user')->whereKey($created->json('id'))->firstOrFail();

    return [$user, $invoice];
}

it('issues an EU reverse-charge invoice once VIES confirms the vat id', function (): void {
    Http::fake(['ec.europa.eu/*' => Http::response(['isValid' => true, 'name' => 'DE Firma', 'address' => 'Berlin'])]);

    [$user, $invoice] = euRcInvoiceReadyForIssue($this);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/status", ['status' => 'sent'])
        ->assertOk()
        ->assertJsonPath('status', 'sent');

    $client = $invoice->client;
    assert($client !== null);
    expect($client->refresh()->vat_verified_at)->not->toBeNull();
});

it('falls back to the grace window when VIES is down but a recent verification exists', function (): void {
    Http::fake(['ec.europa.eu/*' => Http::response('', 500)]);

    [$user, $invoice] = euRcInvoiceReadyForIssue($this, now()->subDays(5)->toISOString());

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/status", ['status' => 'sent'])
        ->assertOk()
        ->assertJsonPath('status', 'sent');
});

it('blocks issuance when VIES has never verified the client and is unreachable', function (): void {
    Http::fake(['ec.europa.eu/*' => Http::response('', 500)]);

    [$user, $invoice] = euRcInvoiceReadyForIssue($this);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/status", ['status' => 'sent'])
        ->assertStatus(422);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft);
});

it('blocks issuance when VIES actively rejects the vat id regardless of a past grace verification', function (): void {
    Http::fake(['ec.europa.eu/*' => Http::response(['isValid' => false, 'name' => '---', 'address' => '---'])]);

    [$user, $invoice] = euRcInvoiceReadyForIssue($this, now()->subDays(5)->toISOString());

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/status", ['status' => 'sent'])
        ->assertStatus(422);
});
