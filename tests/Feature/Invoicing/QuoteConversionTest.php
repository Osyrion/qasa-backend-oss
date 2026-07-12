<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Quote;

/**
 * @return array{0: User, 1: Quote}
 */
function convertibleQuote(string $status = 'sent'): array
{
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $quote = Quote::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => $status,
        'discount_percent' => 10,
    ]);

    $quote->items()->create([
        'description' => 'Konzultácia', 'quantity' => 2, 'unit' => 'hod',
        'unit_price' => 50, 'vat_rate' => 20, 'vat_amount' => 20,
        'total_excl_vat' => 100, 'total_incl_vat' => 120, 'sort_order' => 0,
    ]);
    $quote->load('items')->recalculateTotals()->save();

    return [$user, $quote->refresh()];
}

it('converts a sent quote into a draft invoice with matching items', function (): void {
    [$user, $quote] = convertibleQuote();

    $response = $this->actingAs($user)->postJson("/api/v1/quotes/{$quote->id}/convert-to-invoice");

    $response->assertCreated();
    expect($response->json('status'))->toBe('draft')
        ->and((float) $response->json('discount_percent'))->toBe(10.0);

    $this->assertDatabaseHas('invoice_items', [
        'invoice_id' => $response->json('id'),
        'description' => 'Konzultácia',
    ]);

    expect($quote->refresh()->converted_invoice_id)->toBe($response->json('id'));
});

it('converts an accepted quote into an order with mixed billing', function (): void {
    [$user, $quote] = convertibleQuote('accepted');

    $response = $this->actingAs($user)->postJson("/api/v1/quotes/{$quote->id}/convert-to-order");

    $response->assertCreated();
    expect($response->json('billing_type'))->toBe('mixed')
        ->and($response->json('name'))->toBe($quote->quote_number);

    $this->assertDatabaseHas('order_items', [
        'order_id' => $response->json('id'),
        'description' => 'Konzultácia',
        'type' => 'service',
    ]);

    expect($quote->refresh()->converted_order_id)->toBe($response->json('id'));
});

it('rejects converting the same quote twice', function (): void {
    [$user, $quote] = convertibleQuote();

    $this->actingAs($user)->postJson("/api/v1/quotes/{$quote->id}/convert-to-invoice")->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/quotes/{$quote->id}/convert-to-invoice")->assertStatus(422);
    $this->actingAs($user)->postJson("/api/v1/quotes/{$quote->id}/convert-to-order")->assertStatus(422);
});

it('rejects converting a draft or rejected quote', function (): void {
    [$user, $draft] = convertibleQuote('draft');
    $this->actingAs($user)->postJson("/api/v1/quotes/{$draft->id}/convert-to-invoice")->assertStatus(422);

    [$user2, $rejected] = convertibleQuote('rejected');
    $this->actingAs($user2)->postJson("/api/v1/quotes/{$rejected->id}/convert-to-invoice")->assertStatus(422);
});
