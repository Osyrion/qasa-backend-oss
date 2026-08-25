<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;

/*
 * The counterparty embedded in a document is the same body the clients
 * endpoint returns. Six document resources used to guarantee that by reaching
 * into ClientResource directly, which tied two modules' API shapes together;
 * they go through Clients\Application\Contracts\ClientRepresentation now, and
 * this is what says the shape did not move when they did.
 */

it('embeds the same client body a document as the clients endpoint returns', function (): void {
    $user = createUser();
    $client = Client::factory()->create([
        'user_id' => $user->id,
        'company_name' => 'ACME s.r.o.',
        'ico' => '12345678',
        'email' => 'acme@example.test',
    ]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $embedded = $this->actingAs($user)
        ->getJson("/api/v1/invoices/{$invoice->id}")
        ->assertOk()
        ->json('data.client');

    $direct = $this->actingAs($user)
        ->getJson("/api/v1/clients/{$client->id}")
        ->assertOk()
        ->json('data');

    // contact_persons only appears when that relation is loaded, which the
    // clients endpoint does and the document does not — everything else has
    // to match key for key.
    unset($direct['contact_persons']);

    expect($embedded)->toBe($direct)
        ->and($embedded['id'])->toBe($client->id)
        ->and($embedded['ico'])->toBe('12345678')
        // Not just the party profile: the embed carries the whole clients-API
        // body, which is what the shape guarantee is about.
        ->and($embedded)->toHaveKeys(['is_locked', 'is_archived', 'avatar_path', 'color']);
});
