<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\Quote;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function quotePayload(string $clientId, array $overrides = []): array
{
    return array_merge([
        'client_id' => $clientId,
        'issued_at' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency' => 'EUR',
    ], $overrides);
}

it('creates a quote with a default-masked number', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/quotes', quotePayload($client->id));

    $response->assertCreated();

    expect($response->json('status'))->toBe('draft')
        ->and($response->json('quote_number'))->toStartWith('CP-'.now()->format('Y').'-');

    $this->assertDatabaseHas('quotes', [
        'id' => $response->json('id'),
        'client_id' => $client->id,
        'user_id' => $user->id,
    ]);
});

it('generates sequential quote numbers for consecutive creates', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $first = $this->actingAs($user)->postJson('/api/v1/quotes', quotePayload($client->id));
    $second = $this->actingAs($user)->postJson('/api/v1/quotes', quotePayload($client->id));

    $first->assertCreated();
    $second->assertCreated();

    $firstNumber = (int) Str::afterLast((string) $first->json('quote_number'), '-');
    $secondNumber = (int) Str::afterLast((string) $second->json('quote_number'), '-');

    expect($secondNumber)->toBe($firstNumber + 1);
});

it('uses the tenant custom quote number mask and start', function (): void {
    $user = createUser(['quote_number_mask' => 'Q{YYYY}{NNNN}', 'quote_number_start' => 500]);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/quotes', quotePayload($client->id));

    $response->assertCreated();
    expect($response->json('quote_number'))->toBe('Q'.now()->format('Y').'0500');
});

it('recalculates totals when items are added and removed', function (): void {
    $user = createUser(['country' => 'SK']);
    app(VatRateSeederService::class)->seedFor($user);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $quote = $this->actingAs($user)->postJson('/api/v1/quotes', quotePayload($client->id))->json();

    $item = $this->actingAs($user)->postJson("/api/v1/quotes/{$quote['id']}/items", [
        'description' => 'Konzultácia',
        'quantity' => 2,
        'unit' => 'hod',
        'unit_price' => 50,
        'vat_rate' => 23,
    ]);
    $item->assertCreated();

    $afterAdd = $this->actingAs($user)->getJson("/api/v1/quotes/{$quote['id']}")->json('data');
    expect((float) $afterAdd['subtotal'])->toBe(100.0)
        ->and((float) $afterAdd['vat_amount'])->toBe(23.0)
        ->and((float) $afterAdd['total'])->toBe(123.0);

    $this->actingAs($user)
        ->deleteJson("/api/v1/quotes/{$quote['id']}/items/{$item->json('id')}")
        ->assertNoContent();

    $afterRemove = $this->actingAs($user)->getJson("/api/v1/quotes/{$quote['id']}")->json('data');
    expect((float) $afterRemove['subtotal'])->toBe(0.0)
        ->and((float) $afterRemove['total'])->toBe(0.0);
});

it('rejects editing a sent quote', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $quote = Quote::factory()->sent()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->actingAs($user)
        ->putJson("/api/v1/quotes/{$quote->id}", quotePayload($client->id))
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson("/api/v1/quotes/{$quote->id}/items", [
            'description' => 'x', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 10, 'vat_rate' => 0,
        ])
        ->assertForbidden();
});

it('does not let a user access another account quote', function (): void {
    $victim = createUser();
    $victimClient = Client::factory()->create(['user_id' => $victim->id]);
    $victimQuote = Quote::factory()->draft()->create(['user_id' => $victim->id, 'client_id' => $victimClient->id]);

    $attacker = createUser();

    $this->actingAs($attacker)->getJson("/api/v1/quotes/{$victimQuote->id}")->assertNotFound();
    $this->actingAs($attacker)->deleteJson("/api/v1/quotes/{$victimQuote->id}")->assertNotFound();
});

it('rejects creating a quote for a foreign client', function (): void {
    $user = createUser();
    /** @var User $stranger */
    $stranger = createUser();
    $strangerClient = Client::factory()->create(['user_id' => $stranger->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/quotes', quotePayload($strangerClient->id))
        ->assertStatus(422);
});

it('deletes only a draft quote', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $draft = Quote::factory()->draft()->create(['user_id' => $user->id, 'client_id' => $client->id]);
    $this->actingAs($user)->deleteJson("/api/v1/quotes/{$draft->id}")->assertNoContent();
    $this->assertSoftDeleted('quotes', ['id' => $draft->id]);

    $sent = Quote::factory()->sent()->create(['user_id' => $user->id, 'client_id' => $client->id]);
    $this->actingAs($user)->deleteJson("/api/v1/quotes/{$sent->id}")->assertForbidden();
});
