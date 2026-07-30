<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/** @return TestResponse<Response> */
function createInvoiceForCurrency(TestCase $test, User $user, string $currency): TestResponse
{
    $client = Client::factory()->create(['user_id' => $user->id]);

    return $test->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => $currency,
    ]);
}

it('resolves the currency default account when one exists', function (): void {
    $user = createSaasUser();
    subscribeToPaidPlan($user);
    $czk = BankAccount::factory()->create(['user_id' => $user->id, 'currency' => 'CZK', 'is_default' => true]);
    BankAccount::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'is_primary' => true]);

    $response = createInvoiceForCurrency($this, $user, 'CZK');

    $response->assertCreated();
    expect($response->json('data.bank_account_id'))->toBe($czk->id);
});

it('falls back to the non-default account in the same currency when no default is set', function (): void {
    $user = createSaasUser();
    subscribeToPaidPlan($user);
    $czk = BankAccount::factory()->create(['user_id' => $user->id, 'currency' => 'CZK', 'is_default' => false]);

    $response = createInvoiceForCurrency($this, $user, 'CZK');

    $response->assertCreated();
    expect($response->json('data.bank_account_id'))->toBe($czk->id);
});

it('falls back to the global primary account when no account exists in the invoice currency', function (): void {
    $user = createSaasUser();
    subscribeToPaidPlan($user);
    BankAccount::factory()->create(['user_id' => $user->id, 'currency' => 'CZK', 'is_default' => true]);
    $primary = BankAccount::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'is_primary' => true]);

    $response = createInvoiceForCurrency($this, $user, 'USD');

    $response->assertCreated();
    expect($response->json('data.bank_account_id'))->toBe($primary->id);
});

it('falls back to the oldest account when no primary is set either', function (): void {
    $user = createSaasUser();
    subscribeToPaidPlan($user);
    $oldest = BankAccount::factory()->create(['user_id' => $user->id, 'currency' => 'CZK', 'created_at' => now()->subDays(2)]);
    BankAccount::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'created_at' => now()->subDay()]);

    $response = createInvoiceForCurrency($this, $user, 'USD');

    $response->assertCreated();
    expect($response->json('data.bank_account_id'))->toBe($oldest->id);
});

it('leaves bank_account_id null when the user has no bank accounts', function (): void {
    $user = createSaasUser();
    subscribeToPaidPlan($user);

    $response = createInvoiceForCurrency($this, $user, 'USD');

    $response->assertCreated();
    expect($response->json('data.bank_account_id'))->toBeNull();
});
