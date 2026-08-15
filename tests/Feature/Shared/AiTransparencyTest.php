<?php

declare(strict_types=1);

/**
 * The art. 50 transparency notice is only worth anything if it describes the
 * account it is served to — a notice that says "AI reads your invoices" to an
 * account where extraction is switched off is a false disclosure, not a
 * conservative one. Every case here pins one branch of that.
 *
 * Edition-neutral on purpose: only `invoice_extraction` (Invoicing, core)
 * and the account-level block are asserted here, with no plan/feature setup,
 * so this runs unchanged in the generated OSS core. The premium capabilities
 * and the plan-gated `enabled` branches live in
 * tests/Feature/Saas/AiTransparencyPlanTest.php.
 */
beforeEach(function (): void {
    config([
        'services.anthropic.api_key' => 'sk-ant-platform',
        'invoicing.inbox.extraction.driver' => 'llm',
        'invoicing.inbox.extraction.providers.anthropic.model' => 'claude-haiku-4-5',
    ]);
});

it('describes invoice extraction with its purpose, payload and opt-out', function (): void {
    $owner = createUser(['ai_extraction_enabled' => true]);

    $response = $this->actingAs($owner)->getJson('/api/v1/ai/transparency');

    $response->assertOk();

    /** @var list<array<string, mixed>> $features */
    $features = $response->json('data.features');
    $extraction = array_values(array_filter($features, static fn (array $feature): bool => $feature['key'] === 'invoice_extraction'));

    expect($extraction)->toHaveCount(1)
        ->and($extraction[0]['purpose'])->not->toBe('')
        ->and($extraction[0]['data_sent'])->not->toBe('')
        ->and($extraction[0]['opt_out'])->not->toBe('')
        ->and($extraction[0]['output'])->toBe('suggestion')
        ->and($extraction[0]['human_review_required'])->toBeTrue()
        ->and($extraction[0]['automated_decision_making'])->toBeFalse()
        ->and($response->json('data.automated_decision_making'))->toBeFalse()
        ->and($response->json('data.notice'))->not->toBe('');
});

it('names the model that would receive this account\'s documents', function (): void {
    $owner = createUser(['ai_extraction_enabled' => true]);

    $response = $this->actingAs($owner)->getJson('/api/v1/ai/transparency');

    expect($response->json('data.provider'))->toBe('anthropic')
        ->and($response->json('data.model'))->toBe('claude-haiku-4-5');
});

it('reports no model at all when the deployment has no key configured', function (): void {
    config(['services.anthropic.api_key' => null]);
    $owner = createUser(['ai_extraction_enabled' => true]);

    $response = $this->actingAs($owner)->getJson('/api/v1/ai/transparency');

    expect($response->json('data.provider'))->toBeNull()
        ->and($response->json('data.model'))->toBeNull()
        ->and($response->json('data.own_api_key'))->toBeFalse();
});

it('reports extraction as disabled when the account switched AI off', function (): void {
    $owner = createUser(['ai_extraction_enabled' => false]);

    $response = $this->actingAs($owner)->getJson('/api/v1/ai/transparency');

    /** @var list<array<string, mixed>> $features */
    $features = $response->json('data.features');
    $extraction = array_values(array_filter($features, static fn (array $feature): bool => $feature['key'] === 'invoice_extraction'));

    expect($extraction)->toHaveCount(1)
        ->and($extraction[0]['enabled'])->toBeFalse();
});

it('reports everything disabled behind the platform kill switch', function (): void {
    config(['qasa.ai.disabled' => true]);
    $owner = createUser(['ai_extraction_enabled' => true]);

    $response = $this->actingAs($owner)->getJson('/api/v1/ai/transparency');

    expect($response->json('data.globally_disabled'))->toBeTrue()
        ->and($response->json('data.ai_enabled'))->toBeFalse()
        ->and(array_column((array) $response->json('data.features'), 'enabled'))->each->toBeFalse();
});

it('translates the notice into the caller\'s locale', function (): void {
    $owner = createUser(['locale' => 'sk']);

    $response = $this->actingAs($owner)->getJson('/api/v1/ai/transparency');

    expect($response->json('data.notice'))->toBe(__('ai.transparency.notice', [], 'sk'));
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/ai/transparency')->assertUnauthorized();
});
