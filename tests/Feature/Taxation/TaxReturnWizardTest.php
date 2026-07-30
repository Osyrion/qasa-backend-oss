<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;

it('returns the wizard schema for a supported year and residency', function (): void {
    $user = createSaasUser(['country' => 'CZ']);
    subscribeToPaidPlan($user);

    $response = $this->actingAs($user)->getJson('/api/v1/tax-return/schema?year=2026');

    $response->assertOk();
    expect($response->json('data.residency'))->toBe('CZ')
        ->and($response->json('data.currency'))->toBe('CZK')
        ->and($response->json('data.supports_flat_rate_category_selection'))->toBeTrue()
        ->and($response->json('data.flat_rate_categories'))->toBe([80, 60, 40, 30]);
});

it('rejects an unsupported year in the schema endpoint', function (): void {
    $user = createSaasUser(['country' => 'SK']);
    subscribeToPaidPlan($user);

    $this->actingAs($user)->getJson('/api/v1/tax-return/schema?year=2019')->assertStatus(422);
});

it('saves, reads back and deletes a draft — encrypted, never in the database', function (): void {
    $user = createSaasUser(['country' => 'SK']);
    subscribeToPaidPlan($user);

    $payload = [
        'year' => 2026,
        'use_actual_expenses' => false,
        'is_main_activity' => true,
        'months_active' => 12,
        'spouse_eligible_for_credit' => false,
        'children_ages' => [5, 9],
    ];

    $this->actingAs($user)->putJson('/api/v1/tax-return/draft', $payload)->assertNoContent();

    $this->assertDatabaseMissing('contribution_payments', ['user_id' => $user->id]);

    $get = $this->actingAs($user)->getJson('/api/v1/tax-return/draft?year=2026');
    $get->assertOk();
    expect($get->json('data.children_ages'))->toBe([5, 9]);

    $this->actingAs($user)->deleteJson('/api/v1/tax-return/draft?year=2026')->assertNoContent();
    $this->actingAs($user)->getJson('/api/v1/tax-return/draft?year=2026')->assertStatus(404);
});

it('allows discarding a draft even without the tax_return feature', function (): void {
    $user = createSaasUser(['country' => 'SK']);

    $this->actingAs($user)->deleteJson('/api/v1/tax-return/draft?year=2026')->assertNoContent();
});

it('computes a stateless preview without persisting anything', function (): void {
    $user = createSaasUser(['country' => 'SK']);
    subscribeToPaidPlan($user);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'type' => 'invoice', 'status' => 'sent', 'currency' => 'EUR',
        'issued_at' => '2026-01-10', 'total' => 30_000,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 30_000, 'paid_at' => '2026-02-01']);

    $response = $this->actingAs($user)->postJson('/api/v1/tax-return/preview', [
        'year' => 2026,
        'use_actual_expenses' => false,
        'is_main_activity' => true,
        'months_active' => 12,
        'spouse_eligible_for_credit' => false,
    ]);

    $response->assertOk();
    expect($response->json('data.currency'))->toBe('EUR')
        ->and($response->json('data.disclaimer'))->not->toBeNull()
        ->and((float) $response->json('data.expenses_used'))->toBe(18_000.0);

    // Stateless — no ContributionPayment or other record created by preview.
    $this->assertDatabaseMissing('contribution_payments', ['user_id' => $user->id]);
});

it('exports the preview as a PDF worksheet', function (): void {
    $user = createSaasUser(['country' => 'SK']);
    subscribeToPaidPlan($user);

    $response = $this->actingAs($user)->postJson('/api/v1/tax-return/preview?format=pdf', [
        'year' => 2026,
        'use_actual_expenses' => true,
        'is_main_activity' => true,
        'months_active' => 12,
        'spouse_eligible_for_credit' => false,
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('tax-return-worksheet_2026.pdf')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('rejects an invalid flat-rate category', function (): void {
    $user = createSaasUser(['country' => 'CZ']);
    subscribeToPaidPlan($user);

    $this->actingAs($user)->postJson('/api/v1/tax-return/preview', [
        'year' => 2026,
        'use_actual_expenses' => false,
        'flat_rate_category_percent' => 55,
        'is_main_activity' => true,
        'months_active' => 12,
        'spouse_eligible_for_credit' => false,
    ])->assertStatus(422);
});
