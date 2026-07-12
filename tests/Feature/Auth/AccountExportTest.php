<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\TimeTracking\Domain\Models\Expense;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;

it('exports all sections of the account\'s own data', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);
    TimeEntry::factory()->create(['user_id' => $user->id, 'order_id' => $order->id]);
    Expense::factory()->create(['user_id' => $user->id]);
    BankAccount::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/api/v1/profile/export')->assertOk();

    $payload = json_decode($response->streamedContent(), true);

    expect($payload)->toHaveKeys([
        'exported_at', 'profile', 'clients', 'orders', 'rates', 'price_lists',
        'time_entries', 'expenses', 'exchange_rates', 'bank_accounts', 'invoices',
        'recurring_invoice_templates', 'supplier_invoices', 'calendar_events',
    ]);

    expect($payload['clients'])->toHaveCount(1)
        ->and($payload['orders'])->toHaveCount(1)
        ->and($payload['time_entries'])->toHaveCount(1)
        ->and($payload['expenses'])->toHaveCount(1)
        ->and($payload['bank_accounts'])->toHaveCount(1);
});

it('does not leak sensitive profile columns', function (): void {
    $user = createUser(['clockify_api_key' => 'secret-key']);

    $response = $this->actingAs($user)->get('/api/v1/profile/export')->assertOk();
    $payload = json_decode($response->streamedContent(), true);

    expect($payload['profile'])->not->toHaveKeys(['password', 'remember_token', 'google_id', 'clockify_api_key']);
});

it('does not leak another account\'s data', function (): void {
    $user = createUser();
    $other = createUser();
    Client::factory()->create(['user_id' => $other->id]);

    $response = $this->actingAs($user)->get('/api/v1/profile/export')->assertOk();
    $payload = json_decode($response->streamedContent(), true);

    expect($payload['clients'])->toHaveCount(0);
});
