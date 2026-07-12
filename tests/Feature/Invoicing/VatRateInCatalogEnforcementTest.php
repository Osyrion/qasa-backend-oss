<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\VatRate;

function draftInvoiceForVatCatalogTest(User $user): Invoice
{
    $client = Client::factory()->create(['user_id' => $user->id]);

    return Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'issued_at' => today(),
        'due_at' => today()->addDays(14),
    ]);
}

it('rejects an item VAT rate outside the SK catalog, allows 19% and always allows 0%', function (): void {
    $user = createUser(['country' => 'SK', 'vat_status' => 'payer']);
    app(VatRateSeederService::class)->seedFor($user);
    $invoice = draftInvoiceForVatCatalogTest($user);

    $payload = fn (float $rate) => [
        'description' => 'Konzultácia',
        'quantity' => 1,
        'unit' => 'ks',
        'unit_price' => 100,
        'vat_rate' => $rate,
    ];

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/items", $payload(10))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('vat_rate');

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/items", $payload(19))
        ->assertCreated();

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/items", $payload(0))
        ->assertCreated();
});

it('blocks an expired catalog rate for new items but existing invoices are unaffected', function (): void {
    $user = createUser(['country' => 'SK', 'vat_status' => 'payer']);
    VatRate::factory()->create([
        'user_id' => $user->id, 'country' => 'SK', 'code' => 'SK-10',
        'rate' => 10, 'valid_to' => now()->subDay()->toDateString(),
    ]);
    $invoice = draftInvoiceForVatCatalogTest($user);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'description' => 'Staré služby',
            'quantity' => 1,
            'unit' => 'ks',
            'unit_price' => 100,
            'vat_rate' => 10,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('vat_rate');

    // An item already on the invoice from before the rate expired keeps its
    // frozen numeric rate — no FK, so expiry never touches it.
    $existingItem = $invoice->items()->create([
        'description' => 'Staré služby (pred expiráciou)',
        'quantity' => 1,
        'unit' => 'ks',
        'unit_price' => 100,
        'vat_rate' => 10,
        'vat_amount' => 10,
        'total_excl_vat' => 100,
        'total_incl_vat' => 110,
        'sort_order' => 0,
    ]);

    expect((float) $existingItem->refresh()->vat_rate)->toEqual(10.0);
});
