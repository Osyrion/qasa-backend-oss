<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;

it('returns system-derived income data for the year', function (): void {
    $user = createSaasUser(['country' => 'SK']);
    subscribeToPaidPlan($user);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'type' => 'invoice', 'status' => 'sent', 'currency' => 'EUR',
        'issued_at' => '2026-01-10', 'total' => 500,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 500, 'paid_at' => '2026-02-01']);

    $response = $this->actingAs($user)->getJson('/api/v1/tax-return/system-data?year=2026');

    $response->assertOk();
    expect($response->json('data.currency'))->toBe('EUR')
        ->and((float) $response->json('data.business_income'))->toBe(500.0);
});

it('requires completed tax residency', function (): void {
    $user = createSaasUser(['country' => null]);
    subscribeToPaidPlan($user);

    $this->actingAs($user)->getJson('/api/v1/tax-return/system-data?year=2026')->assertStatus(409);
});

it('rejects a missing year', function (): void {
    $user = createSaasUser(['country' => 'SK']);
    subscribeToPaidPlan($user);

    $this->actingAs($user)->getJson('/api/v1/tax-return/system-data')->assertStatus(422);
});
