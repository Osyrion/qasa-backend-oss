<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\BankAccount;

it('creates, lists, updates and deletes bank accounts', function (): void {
    $user = createUser();

    $create = $this->actingAs($user)->postJson('/api/v1/bank-accounts', [
        'label' => 'Fio EUR',
        'bank_name' => 'Fio banka',
        'iban' => 'CZ5820100000002400123456',
        'bic' => 'FIOBCZPP',
        'currency' => 'EUR',
        'is_default' => true,
    ]);

    $create->assertCreated();
    $id = $create->json('id');

    $this->actingAs($user)->getJson('/api/v1/bank-accounts')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($user)->putJson("/api/v1/bank-accounts/{$id}", [
        'label' => 'Fio EUR renamed',
        'currency' => 'EUR',
        'iban' => 'CZ5820100000002400123456',
        'is_default' => true,
    ])->assertOk()->assertJsonPath('label', 'Fio EUR renamed');

    $this->actingAs($user)->deleteJson("/api/v1/bank-accounts/{$id}")->assertNoContent();

    expect(BankAccount::withoutGlobalScope('user')->count())->toBe(0);
});

it('hides other users bank accounts', function (): void {
    $owner = createUser();
    $account = BankAccount::factory()->create(['user_id' => $owner->id, 'currency' => 'EUR']);

    $intruder = createUser();

    $this->actingAs($intruder)->getJson("/api/v1/bank-accounts/{$account->id}")->assertNotFound();
    $this->actingAs($intruder)->getJson('/api/v1/bank-accounts')->assertOk()->assertJsonCount(0, 'data');
});

it('keeps a single default per currency', function (): void {
    $user = createUser();

    $first = BankAccount::factory()->create([
        'user_id' => $user->id, 'currency' => 'EUR', 'is_default' => true,
    ]);

    $this->actingAs($user)->postJson('/api/v1/bank-accounts', [
        'label' => 'Second EUR',
        'currency' => 'EUR',
        'iban' => 'CZ5820100000002400999999',
        'is_default' => true,
    ])->assertCreated();

    expect($first->refresh()->is_default)->toBeFalse()
        ->and(BankAccount::withoutGlobalScope('user')->where('is_default', true)->count())->toBe(1);
});

it('preselects the currency default bank account on invoice creation', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    BankAccount::factory()->create(['user_id' => $user->id, 'currency' => 'CZK', 'is_default' => true]);
    $eurAccount = BankAccount::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'is_default' => true]);

    $response = $this->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ]);

    $response->assertCreated();
    expect($response->json('bank_account_id'))->toBe($eurAccount->id);
});

it('rejects an invalid IBAN shape', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/bank-accounts', [
        'label' => 'Broken',
        'currency' => 'EUR',
        'iban' => 'not-an-iban',
    ])->assertUnprocessable();
});
