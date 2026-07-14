<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Orders\Domain\Models\Order;

it('exports all sections of the account\'s own data', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);
    Expense::factory()->create(['user_id' => $user->id]);
    BankAccount::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/api/v1/profile/export')->assertOk();

    $payload = json_decode($response->streamedContent(), true);

    expect($payload)->toHaveKeys([
        'exported_at', 'profile', 'clients', 'orders',
        'expenses', 'exchange_rates', 'bank_accounts', 'invoices',
        'recurring_invoice_templates', 'supplier_invoices',
    ]);

    expect($payload['clients'])->toHaveCount(1)
        ->and($payload['orders'])->toHaveCount(1)
        ->and($payload['expenses'])->toHaveCount(1)
        ->and($payload['bank_accounts'])->toHaveCount(1);
});

it('does not leak sensitive profile columns', function (): void {
    $user = createUser();

    $response = $this->actingAs($user)->get('/api/v1/profile/export')->assertOk();
    $payload = json_decode($response->streamedContent(), true);

    expect($payload['profile'])->not->toHaveKeys(['password', 'remember_token', 'google_id']);
});

it('does not leak another account\'s data', function (): void {
    $user = createUser();
    $other = createUser();
    Client::factory()->create(['user_id' => $other->id]);

    $response = $this->actingAs($user)->get('/api/v1/profile/export')->assertOk();
    $payload = json_decode($response->streamedContent(), true);

    expect($payload['clients'])->toHaveCount(0);
});
