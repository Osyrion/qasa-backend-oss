<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;

/**
 * @param  array<string, mixed>  $attributes
 */
function searchableClient(string $userId, array $attributes): Client
{
    return Client::factory()->create([
        'user_id' => $userId,
        'is_customer' => true,
        'archived_at' => null,
        ...$attributes,
    ]);
}

/**
 * @return list<string>
 */
function clientSearchIds(string $term): array
{
    /** @var list<string> */
    return test()->getJson('/api/v1/clients?search='.urlencode($term))
        ->assertOk()
        ->json('data.*.id');
}

it('matches names without diacritics in either direction', function (string $term): void {
    $user = createUser();
    $match = searchableClient($user->id, [
        'client_type' => 'company',
        'name' => null,
        'surname' => null,
        'company_name' => 'Kaviareň Čížek',
    ]);
    $other = searchableClient($user->id, [
        'client_type' => 'company',
        'name' => null,
        'surname' => null,
        'company_name' => 'Pekáreň Novák',
    ]);

    $this->actingAs($user);

    expect(clientSearchIds($term))->toBe([$match->id])
        ->and(clientSearchIds($term))->not->toContain($other->id);
})->with([
    'unaccented needle, accented data' => 'cizek',
    'accented needle, accented data' => 'Čížek',
    'mixed case unaccented' => 'CiZeK',
    'partial mid-word' => 'aviaren',
]);

it('matches an accented needle against unaccented data', function (): void {
    $user = createUser();
    $client = searchableClient($user->id, [
        'client_type' => 'individual',
        'name' => 'Jan',
        'surname' => 'Cizek',
        'company_name' => null,
    ]);

    $this->actingAs($user);

    expect(clientSearchIds('Čížek'))->toBe([$client->id]);
});

it('searches surname, email and ico as well as name', function (string $term): void {
    $user = createUser();
    $client = searchableClient($user->id, [
        'client_type' => 'individual',
        'name' => 'Ján',
        'surname' => 'Kováč',
        'company_name' => null,
        'email' => 'jan.kovac@example.test',
        'ico' => '12345678',
    ]);

    $this->actingAs($user);

    expect(clientSearchIds($term))->toBe([$client->id]);
})->with([
    'name' => 'jan',
    'surname' => 'kovac',
    'email' => 'JAN.KOVAC@EXAMPLE',
    'ico' => '3456',
]);

it('treats % and _ in the needle as literals, not wildcards', function (): void {
    $user = createUser();
    $percent = searchableClient($user->id, [
        'client_type' => 'company',
        'name' => null,
        'surname' => null,
        'company_name' => '50% Sleva',
    ]);
    $underscore = searchableClient($user->id, [
        'client_type' => 'company',
        'name' => null,
        'surname' => null,
        'company_name' => 'a_b Trading',
    ]);
    searchableClient($user->id, [
        'client_type' => 'company',
        'name' => null,
        'surname' => null,
        'company_name' => 'axb Trading',
    ]);

    $this->actingAs($user);

    // Unescaped, '%' would match every row and 'a_b' would also match 'axb'.
    expect(clientSearchIds('%'))->toBe([$percent->id])
        ->and(clientSearchIds('a_b'))->toBe([$underscore->id]);
});

it('keeps the search grouped so it cannot widen other filters', function (): void {
    $user = createUser();
    $other = createUser();

    searchableClient($other->id, [
        'client_type' => 'company',
        'name' => null,
        'surname' => null,
        'company_name' => 'Kaviareň Čížek',
    ]);

    $this->actingAs($user);

    expect(clientSearchIds('cizek'))->toBe([]);
});
