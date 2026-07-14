<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use Illuminate\Support\Facades\Http;

/**
 * @param  list<string>  $accountsXml
 */
function crpdphResponseXml(string $nespolehlivyPlatce, array $accountsXml = []): string
{
    $accounts = implode('', $accountsXml);

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
          <soapenv:Body>
            <StatusNespolehlivyPlatceResponse xmlns="http://adis.mfcr.cz/rozhraniCRPDPH/">
              <status statusCode="0" statusText="OK"/>
              <statusPlatceDPH dic="12345678" nespolehlivyPlatce="{$nespolehlivyPlatce}">
                <zverejneneUcty>{$accounts}</zverejneneUcty>
              </statusPlatceDPH>
            </StatusNespolehlivyPlatceResponse>
          </soapenv:Body>
        </soapenv:Envelope>
        XML;
}

/**
 * @param  array<string, mixed>  $invoiceOverrides
 */
function verifiableInvoice(User $user, string $dic, array $invoiceOverrides = []): SupplierInvoice
{
    $vendor = Client::factory()->vendor()->create([
        'user_id' => $user->id,
        'country' => 'CZ',
        'dic' => $dic,
    ]);

    return SupplierInvoice::factory()->received()->create(array_merge([
        'user_id' => $user->id,
        'client_id' => $vendor->id,
        'vendor_account_number' => '19-2000145399',
        'vendor_bank_code' => '0800',
    ], $invoiceOverrides));
}

it('marks the account as published when it matches a register account', function (): void {
    $user = createUser();
    $invoice = verifiableInvoice($user, '11110001');

    Http::fake(['adisrws.mfcr.cz/*' => Http::response(crpdphResponseXml('NE', [
        '<ucet><standardniUcet predcisli="19" cislo="2000145399" kodBanky="0800"/></ucet>',
    ]))]);

    $response = $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$invoice->id}/verify-account");

    $response->assertOk();

    expect($response->json('result'))->toBe('published')
        ->and($response->json('published_accounts'))->toBe([])
        ->and($invoice->refresh()->account_verification_result)->toBe('published')
        ->and($invoice->account_verified_at)->not->toBeNull();
});

it('matches a stored IBAN against a published domestic account', function (): void {
    $user = createUser();
    $invoice = verifiableInvoice($user, '11110002', [
        'vendor_account_number' => null,
        'vendor_bank_code' => null,
        'vendor_iban' => 'CZ6508000000192000145399',
    ]);

    Http::fake(['adisrws.mfcr.cz/*' => Http::response(crpdphResponseXml('NE', [
        '<ucet><standardniUcet predcisli="19" cislo="2000145399" kodBanky="0800"/></ucet>',
    ]))]);

    $response = $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$invoice->id}/verify-account");

    expect($response->json('result'))->toBe('published');
});

it('reports a mismatch as unpublished and lists the published accounts', function (): void {
    $user = createUser();
    $invoice = verifiableInvoice($user, '11110003');

    Http::fake(['adisrws.mfcr.cz/*' => Http::response(crpdphResponseXml('NE', [
        '<ucet><standardniUcet cislo="9876543210" kodBanky="0300"/></ucet>',
        '<ucet><nestandardniUcet cislo="CZ0203000000000123456789"/></ucet>',
    ]))]);

    $response = $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$invoice->id}/verify-account");

    $response->assertOk();

    expect($response->json('result'))->toBe('unpublished')
        ->and($response->json('published_accounts'))->toHaveCount(2)
        ->and($response->json('published_accounts.0.account_number'))->toBe('9876543210')
        ->and($response->json('published_accounts.1.iban'))->toBe('CZ0203000000000123456789')
        ->and($invoice->refresh()->account_verification_result)->toBe('unpublished');
});

it('flags an unreliable payer regardless of the account', function (): void {
    $user = createUser();
    $invoice = verifiableInvoice($user, '11110004');

    Http::fake(['adisrws.mfcr.cz/*' => Http::response(crpdphResponseXml('ANO', [
        '<ucet><standardniUcet predcisli="19" cislo="2000145399" kodBanky="0800"/></ucet>',
    ]))]);

    $response = $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$invoice->id}/verify-account");

    expect($response->json('result'))->toBe('unreliable')
        ->and($invoice->refresh()->account_verification_result)->toBe('unreliable');
});

it('returns 422 when the register is unreachable', function (): void {
    $user = createUser();
    $invoice = verifiableInvoice($user, '11110005');

    Http::fake(['adisrws.mfcr.cz/*' => Http::response('oops', 500)]);

    $response = $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$invoice->id}/verify-account");

    $response->assertStatus(422);
    expect($invoice->refresh()->account_verification_result)->toBeNull();
});

it('returns 422 for a vendor that is not a CZ VAT payer', function (): void {
    $user = createUser();
    $vendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'SK', 'dic' => '2020202020']);
    $invoice = SupplierInvoice::factory()->received()->create([
        'user_id' => $user->id,
        'client_id' => $vendor->id,
        'vendor_account_number' => '123',
        'vendor_bank_code' => '0800',
    ]);

    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$invoice->id}/verify-account")->assertStatus(422);
});

it('returns 422 when no account is stored', function (): void {
    $user = createUser();
    $invoice = verifiableInvoice($user, '11110006', [
        'vendor_account_number' => null,
        'vendor_bank_code' => null,
    ]);

    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$invoice->id}/verify-account")->assertStatus(422);
});

it('resets the stored verification when the account changes', function (): void {
    $user = createUser(['country' => 'SK']);
    app(VatRateSeederService::class)->seedFor($user);

    $vendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'CZ', 'dic' => '11110007']);
    $invoice = SupplierInvoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $vendor->id,
        'vendor_account_number' => '19-2000145399',
        'vendor_bank_code' => '0800',
        'account_source' => 'ocr',
        'account_verified_at' => now(),
        'account_verification_result' => 'published',
    ]);

    $response = $this->actingAs($user)->putJson("/api/v1/supplier-invoices/{$invoice->id}", [
        'client_id' => $vendor->id,
        'supplier_invoice_number' => 'INV-1',
        'issued_at' => now()->toDateString(),
        'currency' => 'EUR',
        'vat_lines' => [['vat_rate' => 23, 'base' => 100, 'vat_amount' => 23]],
        'vendor_account_number' => '999999999',
        'vendor_bank_code' => '0300',
    ]);

    $response->assertOk();

    expect($invoice->refresh()->vendor_account_number)->toBe('999999999')
        ->and($invoice->account_source)->toBe('manual')
        ->and($invoice->account_verified_at)->toBeNull()
        ->and($invoice->account_verification_result)->toBeNull();
});
