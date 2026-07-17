<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Optional edition overlay — forces env before the app boots.
if (file_exists(__DIR__.'/Pest.edition.php')) {
    require __DIR__.'/Pest.edition.php';
}

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

/**
 * The edition's User model class (auth provider config).
 *
 * @return class-string<User>
 */
function userModel(): string
{
    /** @var class-string<User> $model */
    $model = config('auth.providers.users.model');

    return $model;
}

/**
 * Creates a user of the edition's User model.
 *
 * @param  array<string, mixed>  $attributes
 */
function createUser(array $attributes = []): User
{
    $user = userModel()::factory()->create($attributes);

    // Factory path bypasses RegisterUserAction — an edition overlay can
    // mirror here whatever its registration listeners would do.
    if (function_exists('grantOwnerRole')) {
        grantOwnerRole($user);
    }

    return $user;
}

/**
 * Creates a user with seeded VAT rates, ready to own supplier invoices.
 * Shared by the supplier-invoice and invoice-inbox feature tests.
 *
 * @param  array<string, mixed>  $attributes
 */
function createSupplierInvoiceOwner(array $attributes = []): User
{
    $user = createUser(array_merge(['country' => 'SK'], $attributes));
    app(VatRateSeederService::class)->seedFor($user);

    return $user;
}

function vendorClientFor(User $user): Client
{
    return Client::factory()->vendor()->create(['user_id' => $user->id]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function supplierInvoicePayload(string $clientId, array $overrides = []): array
{
    return array_merge([
        'client_id' => $clientId,
        'supplier_invoice_number' => 'INV-'.fake()->unique()->numberBetween(1, 99999),
        'issued_at' => now()->toDateString(),
        'currency' => 'EUR',
        'vat_lines' => [
            ['vat_rate' => 23, 'base' => 100, 'vat_amount' => 23],
        ],
    ], $overrides);
}

/**
 * A user with seeded VAT rates plus a same-country client, for the
 * VAT control statement tests.
 *
 * @return array{0: User, 1: Client}
 */
function vcsScope(string $country, string $vatStatus = 'payer'): array
{
    $user = createUser(['country' => $country, 'vat_status' => $vatStatus]);
    app(VatRateSeederService::class)->seedFor($user);
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => $country]);

    return [$user, $client];
}

function vcsIssueInvoice(object $test, User $user, Client $client, string $issuedAt, float $unitPrice, float $vatRate): Invoice
{
    $currency = $client->country === 'CZ' ? 'CZK' : 'EUR';

    $created = $test->actingAs($user)->postJson('/api/v1/invoices', [
        'client_id' => $client->id,
        'issued_at' => $issuedAt,
        'due_at' => Carbon::parse($issuedAt)->addDays(14)->toDateString(),
        'currency' => $currency,
    ])->assertCreated();

    $test->actingAs($user)->postJson("/api/v1/invoices/{$created->json('id')}/items", [
        'description' => 'Služby', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => $unitPrice, 'vat_rate' => $vatRate,
    ])->assertCreated();

    $test->actingAs($user)->postJson("/api/v1/invoices/{$created->json('id')}/status", ['status' => 'sent'])
        ->assertOk();

    return Invoice::withoutGlobalScope('user')->whereKey($created->json('id'))->firstOrFail();
}
