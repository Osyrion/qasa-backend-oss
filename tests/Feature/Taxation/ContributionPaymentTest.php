<?php

declare(strict_types=1);

use App\Modules\Taxation\Domain\Models\ContributionPayment;

it('creates a contribution payment', function (): void {
    $user = createUser(['country' => 'SK']);

    $response = $this->actingAs($user)->postJson('/api/v1/contributions', [
        'type' => 'social',
        'period_year' => 2026,
        'period_month' => 3,
        'amount' => 250.50,
        'currency' => 'EUR',
        'paid_at' => '2026-03-08',
        'note' => 'Marcová platba',
    ]);

    $response->assertCreated();
    expect($response->json('data.type'))->toBe('social')
        ->and((float) $response->json('data.amount'))->toBe(250.5);

    $this->assertDatabaseHas('contribution_payments', [
        'id' => $response->json('data.id'),
        'user_id' => $user->id,
        'type' => 'social',
    ]);
});

it('rejects an invalid contribution type', function (): void {
    $user = createUser(['country' => 'SK']);

    $this->actingAs($user)->postJson('/api/v1/contributions', [
        'type' => 'pension',
        'period_year' => 2026,
        'amount' => 100,
        'currency' => 'EUR',
        'paid_at' => '2026-03-08',
    ])->assertStatus(422);
});

it('lists only the account own contribution payments', function (): void {
    $user = createUser(['country' => 'SK']);
    $other = createUser(['country' => 'SK']);

    asAccount($user, fn () => ContributionPayment::factory()->for($user)->create(['type' => 'social']));
    ContributionPayment::factory()->for($other)->create(['type' => 'health']);

    $response = $this->actingAs($user)->getJson('/api/v1/contributions');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('filters contributions by type and year', function (): void {
    $user = createUser(['country' => 'SK']);

    ContributionPayment::factory()->for($user)->create(['type' => 'social', 'period_year' => 2025, 'paid_at' => '2025-05-01']);
    ContributionPayment::factory()->for($user)->create(['type' => 'health', 'period_year' => 2026, 'paid_at' => '2026-05-01']);

    $response = $this->actingAs($user)->getJson('/api/v1/contributions?type=health&year=2026');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.type'))->toBe('health');
});

it('updates and deletes a contribution payment', function (): void {
    $user = createUser(['country' => 'SK']);
    $payment = ContributionPayment::factory()->for($user)->create(['type' => 'social', 'amount' => 100]);

    $response = $this->actingAs($user)->putJson("/api/v1/contributions/{$payment->id}", [
        'type' => 'social',
        'period_year' => $payment->period_year,
        'amount' => 200,
        'currency' => $payment->currency->value,
        'paid_at' => $payment->paid_at->toDateString(),
    ]);
    $response->assertOk();
    expect((float) $response->json('data.amount'))->toBe(200.0);

    $this->actingAs($user)->deleteJson("/api/v1/contributions/{$payment->id}")->assertNoContent();
    $this->assertSoftDeleted('contribution_payments', ['id' => $payment->id]);
});

it('does not let a user access another account contribution payment', function (): void {
    $victim = createUser(['country' => 'SK']);
    $payment = ContributionPayment::factory()->for($victim)->create();

    $attacker = createUser(['country' => 'SK']);

    $this->actingAs($attacker)->getJson("/api/v1/contributions/{$payment->id}")->assertNotFound();
    $this->actingAs($attacker)->deleteJson("/api/v1/contributions/{$payment->id}")->assertNotFound();
});

it('returns a yearly summary grouped by type and currency', function (): void {
    $user = createUser(['country' => 'SK']);

    ContributionPayment::factory()->for($user)->create(['type' => 'social', 'currency' => 'EUR', 'amount' => 100, 'period_year' => 2026]);
    ContributionPayment::factory()->for($user)->create(['type' => 'social', 'currency' => 'EUR', 'amount' => 150, 'period_year' => 2026]);
    ContributionPayment::factory()->for($user)->create(['type' => 'health', 'currency' => 'EUR', 'amount' => 80, 'period_year' => 2026]);
    ContributionPayment::factory()->for($user)->create(['type' => 'social', 'currency' => 'EUR', 'amount' => 999, 'period_year' => 2025]);

    $response = $this->actingAs($user)->getJson('/api/v1/contributions/summary?year=2026');

    $response->assertOk();
    expect((float) $response->json('totals.social.EUR'))->toBe(250.0)
        ->and((float) $response->json('totals.health.EUR'))->toBe(80.0)
        ->and($response->json('totals.income_tax_advance'))->toBe([]);
});
