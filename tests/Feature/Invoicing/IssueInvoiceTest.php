<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\TimeTracking\Domain\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @param  array<string, mixed>  $userAttributes
 * @return array{0: User, 1: Invoice}
 */
function draftInvoice(string $currency = 'EUR', array $userAttributes = []): array
{
    $user = createUser([
        'ico' => '12345678',
        'dic' => 'CZ12345678',
        'is_vat_payer' => true,
        'vat_status' => 'payer',
        'country' => 'CZ',
        ...$userAttributes,
    ]);
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => 'SK']);
    $bank = BankAccount::factory()->create([
        'user_id' => $user->id,
        'currency' => $currency,
        'is_default' => true,
    ]);

    $invoice = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'invoice',
        'currency' => $currency,
        'bank_account_id' => $bank->id,
        'exchange_rate_snapshot' => null,
        'variable_symbol' => null,
        'taxable_supply_at' => null,
        'discount_percent' => null,
    ]);

    return [$user, $invoice];
}

/** @return TestResponse<Response> */
function issue(object $test, User $user, Invoice $invoice): TestResponse
{
    return $test->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/status", [
        'status' => 'sent',
    ]);
}

function fakeCnb(float $rate = 24.755): void
{
    Http::fake([
        'api.cnb.cz/*' => Http::response([
            'rates' => [
                ['currencyCode' => 'EUR', 'amount' => 1, 'rate' => $rate],
                ['currencyCode' => 'USD', 'amount' => 1, 'rate' => 22.5],
            ],
        ]),
    ]);
}

it('snapshots the ČNB rate and caches it as a system exchange rate', function (): void {
    fakeCnb();

    [$user, $invoice] = draftInvoice('EUR');
    issue($this, $user, $invoice)->assertOk();

    $invoice->refresh();

    expect((float) $invoice->exchange_rate_snapshot)->toBe(24.755);

    $cached = ExchangeRate::withoutGlobalScope('user')
        ->whereNull('user_id')
        ->where('base_currency', 'EUR')
        ->where('target_currency', 'CZK')
        ->first();

    expect($cached)->not->toBeNull()
        ->and($cached?->source?->value)->toBe('cnb');
});

it('does not call ČNB for CZK invoices', function (): void {
    Http::fake();

    [$user, $invoice] = draftInvoice('CZK');
    issue($this, $user, $invoice)->assertOk();

    Http::assertNothingSent();
    expect($invoice->refresh()->exchange_rate_snapshot)->toBeNull();
});

it('freezes supplier, client and bank snapshots at issue', function (): void {
    fakeCnb();

    [$user, $invoice] = draftInvoice('EUR');
    issue($this, $user, $invoice)->assertOk();

    $invoice->refresh();

    expect($invoice->supplier_snapshot)->not->toBeNull()
        ->and($invoice->supplier_snapshot['ico'] ?? null)->toBe('12345678')
        ->and($invoice->client_snapshot['country'] ?? null)->toBe('SK')
        ->and($invoice->bank_account_snapshot['iban'] ?? null)->not->toBeNull();

    // Later profile edits don't touch the frozen snapshot
    $user->update(['ico' => '99999999']);
    expect($invoice->refresh()->supplier_snapshot['ico'] ?? null)->toBe('12345678');
});

it('freezes snapshots on draft → issued too, not only draft → sent', function (): void {
    fakeCnb();

    [$user, $invoice] = draftInvoice('EUR');

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/status", [
        'status' => 'issued',
    ])->assertOk();

    $invoice->refresh();

    expect($invoice->status)->toBe('issued')
        ->and($invoice->supplier_snapshot)->not->toBeNull()
        ->and($invoice->supplier_snapshot['ico'] ?? null)->toBe('12345678')
        ->and($invoice->client_snapshot['country'] ?? null)->toBe('SK')
        ->and($invoice->bank_account_snapshot['iban'] ?? null)->not->toBeNull()
        ->and((float) $invoice->exchange_rate_snapshot)->toBe(24.755)
        ->and($invoice->variable_symbol)->not->toBeNull()
        ->and($invoice->taxable_supply_at)->not->toBeNull();
});

it('defaults the variable symbol and DUZP at issue', function (): void {
    fakeCnb();

    [$user, $invoice] = draftInvoice('EUR');
    issue($this, $user, $invoice)->assertOk();

    $invoice->refresh();

    expect($invoice->variable_symbol)->not->toBeNull()
        ->and($invoice->taxable_supply_at)->not->toBeNull();
});

it('still issues the invoice when ČNB is down', function (): void {
    Http::fake(['api.cnb.cz/*' => Http::response(null, 500)]);

    [$user, $invoice] = draftInvoice('EUR');
    issue($this, $user, $invoice)->assertOk();

    $invoice->refresh();

    expect($invoice->status)->toBe('sent')
        ->and($invoice->exchange_rate_snapshot)->toBeNull();
});

it('prefers a stored manual rate for the issue date over ČNB', function (): void {
    Http::fake();

    [$user, $invoice] = draftInvoice('EUR');

    ExchangeRate::create([
        'user_id' => $invoice->user_id,
        'base_currency' => 'EUR',
        'target_currency' => 'CZK',
        'rate' => 25.5,
        'date' => $invoice->issued_at->toDateString(),
        'source' => 'manual',
    ]);

    issue($this, $user, $invoice)->assertOk();

    Http::assertNothingSent();
    expect((float) $invoice->refresh()->exchange_rate_snapshot)->toBe(25.5);
});
