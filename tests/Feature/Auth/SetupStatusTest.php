<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\Invoice;

/**
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function setupStatusItems(array $body): array
{
    /** @var list<array<string, mixed>> $items */
    $items = $body['data']['items'];

    return collect($items)->keyBy('key')->all();
}

it('reports a fresh account as incomplete on every required item', function (): void {
    $user = createUser([
        'ico' => null, 'address' => null, 'city' => null,
        'logo_path' => null,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/profile/setup-status')->assertOk();

    $items = setupStatusItems($response->json());

    expect($items['billing_identity']['done'])->toBeFalse()
        ->and($items['billing_identity']['optional'])->toBeFalse()
        ->and($items['vat_status']['done'])->toBeFalse()
        ->and($items['vat_status']['optional'])->toBeFalse()
        ->and($items['bank_account']['done'])->toBeFalse()
        ->and($items['bank_account']['optional'])->toBeFalse()
        ->and($items['first_client']['done'])->toBeFalse()
        ->and($items['first_client']['optional'])->toBeFalse()
        ->and($items['logo']['done'])->toBeFalse()
        ->and($items['logo']['optional'])->toBeTrue()
        ->and($items['first_invoice']['done'])->toBeFalse()
        ->and($items['first_invoice']['optional'])->toBeTrue()
        // invoice_prefix defaults to "FA" on registration, so the default
        // mask already works — optional, and true out of the box.
        ->and($items['invoice_numbering']['done'])->toBeTrue()
        ->and($items['invoice_numbering']['optional'])->toBeTrue()
        ->and($response->json('data.completed'))->toBeFalse();
});

it('flips items to done as the account is filled in, and completed once all required items are done', function (): void {
    $user = createUser([
        'ico' => null, 'address' => null, 'city' => null,
        'logo_path' => null,
    ]);

    $this->actingAs($user)->putJson('/api/v1/auth/profile', [
        'ico' => '12345678',
        'address' => 'Hlavná 1',
        'city' => 'Bratislava',
        'vat_status' => 'non_payer',
    ])->assertOk();

    $status = $this->actingAs($user)->getJson('/api/v1/profile/setup-status')->assertOk();
    $items = setupStatusItems($status->json());

    expect($items['billing_identity']['done'])->toBeTrue()
        ->and($items['vat_status']['done'])->toBeTrue()
        ->and($items['bank_account']['done'])->toBeFalse()
        ->and($items['first_client']['done'])->toBeFalse()
        ->and($status->json('data.completed'))->toBeFalse();

    BankAccount::factory()->create(['user_id' => $user->id]);
    Client::factory()->create(['user_id' => $user->id]);

    $status = $this->actingAs($user)->getJson('/api/v1/profile/setup-status')->assertOk();
    $items = setupStatusItems($status->json());

    expect($items['bank_account']['done'])->toBeTrue()
        ->and($items['first_client']['done'])->toBeTrue()
        ->and($status->json('data.completed'))->toBeTrue();
});

it('ignores optional items when computing completed', function (): void {
    $user = createUser([
        'ico' => '12345678', 'address' => 'Hlavná 1', 'city' => 'Bratislava',
        'vat_status' => 'non_payer', 'vat_status_confirmed_at' => now(),
        'logo_path' => null,
    ]);
    BankAccount::factory()->create(['user_id' => $user->id]);
    Client::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/profile/setup-status')->assertOk();
    $items = setupStatusItems($response->json());

    expect($items['logo']['done'])->toBeFalse()
        ->and($items['first_invoice']['done'])->toBeFalse()
        ->and($response->json('data.completed'))->toBeTrue();
});

it('marks first_invoice and logo done once present', function (): void {
    $user = createUser([
        'ico' => '12345678', 'address' => 'Hlavná 1', 'city' => 'Bratislava',
        'vat_status' => 'non_payer', 'vat_status_confirmed_at' => now(),
        'logo_path' => 'logos/foo.png',
    ]);
    BankAccount::factory()->create(['user_id' => $user->id]);
    $client = Client::factory()->create(['user_id' => $user->id]);
    Invoice::factory()->draft()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/profile/setup-status')->assertOk();
    $items = setupStatusItems($response->json());

    expect($items['logo']['done'])->toBeTrue()
        ->and($items['first_invoice']['done'])->toBeTrue()
        ->and($response->json('data.completed'))->toBeTrue();
});
