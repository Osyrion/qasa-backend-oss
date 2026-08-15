<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;

/**
 * Art. 50 ods. 2 asks for the marking to be machine-readable, which in a
 * JSON API means a field a client can key off without parsing prose. These
 * pin that the marking is attached to what a model produced and — just as
 * importantly — is absent from what a regex or a UBL document produced.
 */
it('marks AI-extracted inbox suggestions with the provider and model that produced them', function (): void {
    $owner = createUser();
    $item = InvoiceInboxItem::factory()->create([
        'user_id' => $owner->id,
        'status' => 'pending',
        'suggestions' => ['supplier_invoice_number' => 'AI-001'],
        'suggestions_source' => 'ai',
        'suggestions_provider' => 'anthropic',
        'suggestions_model' => 'claude-haiku-4-5',
    ]);

    $response = $this->actingAs($owner)->getJson("/api/v1/invoice-inbox/{$item->id}");

    $response->assertOk();

    expect($response->json('data.ai.generated'))->toBeTrue()
        ->and($response->json('data.ai.provider'))->toBe('anthropic')
        ->and($response->json('data.ai.model'))->toBe('claude-haiku-4-5')
        ->and($response->json('data.ai.human_review_required'))->toBeTrue()
        ->and($response->json('data.ai.notice'))->toBe(__('ai.output_notice'));
});

it('does not mark suggestions the regex extractor produced', function (): void {
    $owner = createUser();
    $item = InvoiceInboxItem::factory()->create([
        'user_id' => $owner->id,
        'status' => 'pending',
        'suggestions' => ['supplier_invoice_number' => 'REGEX-001'],
        'suggestions_source' => 'regex',
        'suggestions_provider' => null,
        'suggestions_model' => null,
    ]);

    $response = $this->actingAs($owner)->getJson("/api/v1/invoice-inbox/{$item->id}");

    $response->assertOk();

    expect($response->json('data.ai'))->toBeNull();
});
