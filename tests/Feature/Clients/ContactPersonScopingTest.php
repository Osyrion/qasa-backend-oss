<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Domain\Models\ContactPerson;

/**
 * PUT and DELETE on `clients/{client}/contact-persons/{contactPerson}` were
 * dead on arrival — a 500 on every call, for as long as they have existed.
 *
 * The actions declared only the ContactPerson and authorized against *its*
 * own client, never reading the {client} segment. Route parameters are handed
 * to an action positionally, though, so the undeclared client id took the
 * $contactPerson slot and the call died with a TypeError before running a
 * line. Nothing tested those two verbs, so a nested route that reads correct
 * at a glance shipped broken; RoutePermissionCoverageTest now checks the
 * declaration rule against the whole route table.
 *
 * Declaring {client} fixes the crash and makes the path mean what it says.
 * Scoping the child to it is the second half: not a tenancy control (the
 * Policy answers that, RLS behind it) but the difference between a nested
 * path that addresses one client's contact person and one that addresses any
 * of the caller's. scopeBindings() cannot express it here — Laravel derives
 * the relation from the parameter name and {contactPerson} pluralises to
 * contactPeople(), not contactPersons() — so the controller checks the parent
 * explicitly, the way price-list items and bank-connection suggestions do.
 */
it('does not reach a contact person through another client of the same account', function (string $method): void {
    $user = createUser();

    $owner = Client::factory()->for($user)->create();
    $bystander = Client::factory()->for($user)->create();
    $contact = ContactPerson::factory()->for($owner)->create();

    $payload = $method === 'putJson' ? [[
        'name' => 'Ján',
        'surname' => 'Novák',
    ]] : [];

    $this->actingAs($user)
        ->{$method}("/api/v1/clients/{$bystander->id}/contact-persons/{$contact->id}", ...$payload)
        ->assertNotFound();

    expect(ContactPerson::query()->find($contact->id))->not->toBeNull();
})->with(['putJson', 'deleteJson']);

it('still reaches the contact person through its own client', function (): void {
    $user = createUser();

    $client = Client::factory()->for($user)->create();
    $contact = ContactPerson::factory()->for($client)->create();

    $this->actingAs($user)
        ->putJson("/api/v1/clients/{$client->id}/contact-persons/{$contact->id}", [
            'name' => 'Ján',
            'surname' => 'Novák',
        ])
        ->assertOk();

    expect($contact->fresh())
        ->name->toBe('Ján')
        ->surname->toBe('Novák');
});
