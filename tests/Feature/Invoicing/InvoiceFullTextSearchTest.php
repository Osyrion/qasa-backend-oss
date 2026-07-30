<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;

/**
 * @return list<string>
 */
function invoiceSearchIds(string $term): array
{
    /** @var list<string> */
    return test()->getJson('/api/v1/invoices?search='.urlencode($term))
        ->assertOk()
        ->json('data.*.id');
}

function invoiceWithNote(string $userId, string $clientId, string $note): Invoice
{
    return Invoice::factory()->create([
        'user_id' => $userId,
        'client_id' => $clientId,
        'note' => $note,
    ]);
}

it('finds an invoice by words in its note, ignoring diacritics and inflection', function (string $term): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $match = invoiceWithNote($user->id, $client->id, 'Rekonštrukcia strechy na chate');
    invoiceWithNote($user->id, $client->id, 'Nátery a maľby v byte');

    $this->actingAs($user);

    expect(invoiceSearchIds($term))->toBe([$match->id]);
})->with([
    'exact word' => 'rekonstrukcia',
    'with diacritics' => 'Rekonštrukcia',
    // A prefix query is what stands in for the stemming Slovak does not have.
    'inflected form' => 'rekonstrukci',
    'mixed case' => 'STRECHY',
    'two words, both must match' => 'rekonstrukcia strechy',
]);

it('requires every word of a multi-word search to match', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    invoiceWithNote($user->id, $client->id, 'Rekonštrukcia strechy');

    $this->actingAs($user);

    expect(invoiceSearchIds('rekonstrukcia fasady'))->toBe([]);
});

it('finds an invoice by the description of one of its line items', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $match = Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id, 'note' => null]);
    InvoiceItem::factory()->create(['invoice_id' => $match->id, 'description' => 'Montáž zábradlia']);

    $other = Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id, 'note' => null]);
    InvoiceItem::factory()->create(['invoice_id' => $other->id, 'description' => 'Doprava materiálu']);

    $this->actingAs($user);

    expect(invoiceSearchIds('zabradlia'))->toBe([$match->id]);
});

it('still finds an invoice by number, symbol and client name', function (): void {
    $user = createUser();
    $client = Client::factory()->create([
        'user_id' => $user->id,
        'client_type' => 'company',
        'name' => null,
        'surname' => null,
        'company_name' => 'Kaviareň Čížek',
    ]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'invoice_number' => 'FA-2026-0042',
        'variable_symbol' => '20260042',
        'note' => null,
    ]);

    $this->actingAs($user);

    // The client name now goes through the same diacritics folding as the
    // client list search.
    expect(invoiceSearchIds('2026-0042'))->toBe([$invoice->id])
        ->and(invoiceSearchIds('20260042'))->toBe([$invoice->id])
        ->and(invoiceSearchIds('cizek'))->toBe([$invoice->id]);
});

it('ignores a search made only of punctuation', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    invoiceWithNote($user->id, $client->id, 'Rekonštrukcia strechy');

    $this->actingAs($user);

    // Nothing tokenisable, so the full-text half is skipped rather than
    // building an invalid tsquery — the LIKE half still runs and matches
    // nothing.
    expect(invoiceSearchIds('&&&'))->toBe([]);
});
