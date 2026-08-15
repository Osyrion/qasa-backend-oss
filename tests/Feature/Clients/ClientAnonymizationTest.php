<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Actions\IssueInvoiceAction;
use App\Modules\Invoicing\Application\Services\InvoicePdfService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 of docs/plans/GDPR_COMPLIANCE_PLAN.md.
 *
 * A client who is a natural person is a data subject, and until now there
 * was no way to honour an erasure request for one: DeleteClientAction
 * refuses to delete a client with non-cancelled invoices, and rightly so.
 *
 * Anonymisation is the answer, and it rests on the same fact phase 1 does —
 * an issued invoice carries client_snapshot frozen at issue, so the document
 * survives the identity behind it being removed.
 *
 * @return array{0: User, 1: Client, 2: Invoice}
 */
function clientWithIssuedInvoice(): array
{
    $user = createUser(['country' => 'SK']);

    return asAccount($user, function () use ($user): array {
        $client = Client::factory()->create([
            'user_id' => $user->id,
            'client_type' => 'company',
            'company_name' => 'Odberateľ s.r.o.',
            'ico' => '87654321',
            'vat_id' => 'SK2020202020',
            'email' => 'kontakt@odberatel.test',
            'phone' => '+421900111222',
            'address' => 'Hlavná 5',
        ]);
        // bank_iban and external_id are premium columns on this core table
        // and are asserted in tests/Feature/Saas — they do not exist at all
        // in the generated OSS core, which is how this test discovered the
        // action was naming them.

        $client->contactPersons()->create([
            'name' => 'Peter',
            'surname' => 'Novák',
            'email' => 'peter@odberatel.test',
            'is_primary' => true,
        ]);

        $invoice = Invoice::factory()->draft()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'invoice_number' => null,
        ]);

        $invoice->items()->create([
            'description' => 'Vývoj aplikácie',
            'quantity' => 1,
            'unit' => 'hod',
            'unit_price' => 100,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total_excl_vat' => 100,
            'total_incl_vat' => 100,
            'sort_order' => 0,
        ]);

        $issued = app(IssueInvoiceAction::class)->execute($invoice->refresh());

        return [$user, $client, $issued];
    });
}

it('clears the client\'s personal data', function (): void {
    [$user, $client] = clientWithIssuedInvoice();

    $this->actingAs($user)
        ->postJson("/api/v1/clients/{$client->id}/anonymize")
        ->assertOk();

    $fresh = asAccount($user, fn (): Client => Client::query()->whereKey($client->id)->sole());

    expect($fresh->company_name)->toBe(Client::ANONYMISED)
        ->and($fresh->name)->toBeNull()
        ->and($fresh->surname)->toBeNull()
        ->and($fresh->ico)->toBeNull()
        ->and($fresh->dic)->toBeNull()
        ->and($fresh->vat_id)->toBeNull()
        ->and($fresh->email)->toBeNull()
        ->and($fresh->phone)->toBeNull()
        ->and($fresh->address)->toBeNull()
        ->and($fresh->city)->toBeNull()
        ->and($fresh->postal_code)->toBeNull()
        ->and($fresh->note)->toBeNull()
        ->and($fresh->anonymized_at)->not->toBeNull();
});

it('leaves the issued invoice\'s snapshot byte for byte unchanged', function (): void {
    [$user, $client, $invoice] = clientWithIssuedInvoice();

    $before = asAccount($user, fn (): object => DB::table('invoices')->where('id', $invoice->id)->sole());

    $this->actingAs($user)->postJson("/api/v1/clients/{$client->id}/anonymize")->assertOk();

    $after = asAccount($user, fn (): object => DB::table('invoices')->where('id', $invoice->id)->sole());

    // The decision this whole phase rests on (owner, 2026-08-07): an issued
    // invoice is an archived accounting record with its own legal basis, and
    // erasure does not reach into it. If someone ever widens the anonymisation
    // to invoices, this is the test that must stop them.
    expect($after->client_snapshot)->toBe($before->client_snapshot)
        ->and($after->supplier_snapshot)->toBe($before->supplier_snapshot);

    expect(json_decode((string) $after->client_snapshot, true))
        ->toHaveKey('name', 'Odberateľ s.r.o.');
});

it('still renders the issued invoice with the original client name', function (): void {
    [$user, $client, $invoice] = clientWithIssuedInvoice();

    $this->actingAs($user)->postJson("/api/v1/clients/{$client->id}/anonymize")->assertOk();

    $html = asAccount($user, function () use ($invoice): string {
        $service = app(InvoicePdfService::class);

        // Re-read from the database rather than reusing the instance from
        // setup: that one still carries the client relation loaded *before*
        // the anonymisation, so loadMissing() would skip it and the render
        // would show the original name whether or not the snapshot works.
        $fresh = Invoice::query()
            ->with(['client', 'items', 'user', 'bankAccount', 'relatedInvoice', 'workReportLines'])
            ->whereKey($invoice->id)
            ->sole();

        return view('invoices::pdf', ['vm' => $service->viewModel($fresh)])->render();
    });

    expect($html)->toContain('Odberateľ s.r.o.');
});

it('deletes the contact persons', function (): void {
    [$user, $client] = clientWithIssuedInvoice();

    $this->actingAs($user)->postJson("/api/v1/clients/{$client->id}/anonymize")->assertOk();

    // Nothing has a foreign key to contact_persons, so they can simply go —
    // deleting is truer erasure than blanking two NOT NULL columns.
    expect(asAccount($user, fn (): int => DB::table('contact_persons')
        ->where('client_id', $client->id)->count()))->toBe(0);
});

it('archives the client so it cannot be used on a new document', function (): void {
    [$user, $client] = clientWithIssuedInvoice();

    $this->actingAs($user)->postJson("/api/v1/clients/{$client->id}/anonymize")->assertOk();

    $fresh = asAccount($user, fn (): Client => Client::query()->whereKey($client->id)->sole());

    expect($fresh->isArchived())->toBeTrue();
});

it('reports how many drafts will show the anonymised data', function (): void {
    [$user, $client] = clientWithIssuedInvoice();

    asAccount($user, fn () => Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]));

    // A draft has no snapshot yet (IssueInvoiceAction freezes it at issue), so
    // it falls back to the live client and will read as anonymised. Correct —
    // a draft is not a document — but the user must see it before confirming,
    // not discover it afterwards.
    $this->actingAs($user)
        ->postJson("/api/v1/clients/{$client->id}/anonymize")
        ->assertOk()
        ->assertJsonPath('affected_drafts', 1);
});

it('is idempotent', function (): void {
    [$user, $client] = clientWithIssuedInvoice();

    $this->actingAs($user)->postJson("/api/v1/clients/{$client->id}/anonymize")->assertOk();

    $first = asAccount($user, fn (): Client => Client::query()->whereKey($client->id)->sole());

    $this->actingAs($user)->postJson("/api/v1/clients/{$client->id}/anonymize")->assertOk();

    $second = asAccount($user, fn (): Client => Client::query()->whereKey($client->id)->sole());

    expect($second->anonymized_at?->toISOString())->toBe($first->anonymized_at?->toISOString());
});

it('refuses to anonymise another account\'s client', function (): void {
    [, $client] = clientWithIssuedInvoice();
    $stranger = createUser();

    $this->actingAs($stranger)
        ->postJson("/api/v1/clients/{$client->id}/anonymize")
        ->assertNotFound();
});

it('leaves plain deletion alone for a client with no documents', function (): void {
    $user = createUser();
    $client = asAccount($user, fn (): Client => Client::factory()->create(['user_id' => $user->id]));

    // Anonymisation is the path for a client that cannot be deleted, not a
    // replacement for deleting one that can.
    $this->actingAs($user)->deleteJson("/api/v1/clients/{$client->id}")->assertNoContent();

    expect(asAccount($user, fn (): bool => Client::whereKey($client->id)->exists()))->toBeFalse();
});
