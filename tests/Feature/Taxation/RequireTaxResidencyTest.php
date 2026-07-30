<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;

/*
|--------------------------------------------------------------------------
| Phase 1 — RequireTaxResidency enforcement
| (docs/plans/TAX_RESIDENCY_PHASE_1_REGISTRATION_RESIDENCY.md §1.5)
|--------------------------------------------------------------------------
*/

it('blocks creating an invoice without completed residency', function (): void {
    $user = createUser(['country' => null]);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => now()->toDateString(),
        'due_at' => now()->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ])->assertStatus(409);
});

it('blocks creating a quote without completed residency', function (): void {
    $user = createUser(['country' => null]);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->postJson('/api/v1/quotes', [
        'client_id' => $client->id,
        'issued_at' => now()->toDateString(),
        'valid_until' => now()->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ])->assertStatus(409);
});

it('blocks creating an order without completed residency', function (): void {
    $user = createUser(['country' => null]);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->postJson('/api/v1/orders', [
        'client_id' => $client->id,
        'title' => 'Zákazka',
    ])->assertStatus(409);
});

it('allows document routes once residency is completed', function (): void {
    $user = createUser(['country' => 'SK']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => now()->toDateString(),
        'due_at' => now()->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ])->assertCreated();
});
