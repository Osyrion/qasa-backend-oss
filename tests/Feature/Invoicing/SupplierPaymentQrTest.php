<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\SupplierPaymentQrService;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Enums\Currency;

/**
 * @param  array<string, mixed>  $overrides
 */
function qrSupplierInvoice(User $user, array $overrides = []): SupplierInvoice
{
    $vendor = Client::factory()->vendor()->company()->create([
        'user_id' => $user->id,
        'company_name' => 'Dodávateľ s.r.o.',
    ]);

    return SupplierInvoice::factory()->received()->create(array_merge([
        'user_id' => $user->id,
        'client_id' => $vendor->id,
        'currency' => Currency::CZK->value,
        'variable_symbol' => '20260001',
        'total' => 121.50,
    ], $overrides));
}

it('builds a SPAYD payload for a CZK invoice, converting the domestic account to IBAN', function (): void {
    $user = createUser();
    $invoice = qrSupplierInvoice($user, [
        'vendor_account_number' => '19-2000145399',
        'vendor_bank_code' => '0800',
    ]);

    $payload = app(SupplierPaymentQrService::class)->payload($invoice);

    expect($payload)->toStartWith('SPD*1.0')
        ->and($payload)->toContain('ACC:CZ6508000000192000145399')
        ->and($payload)->toContain('AM:121.50')
        ->and($payload)->toContain('CC:CZK')
        ->and($payload)->toContain('X-VS:20260001');
});

it('builds an EPC payload for an EUR invoice with an IBAN', function (): void {
    $user = createUser();
    $invoice = qrSupplierInvoice($user, [
        'currency' => Currency::EUR->value,
        'vendor_iban' => 'SK3112000000198742637541',
        'vendor_bic' => 'BREXSKBX',
    ]);

    $payload = app(SupplierPaymentQrService::class)->payload($invoice);

    expect($payload)->toStartWith("BCD\n002")
        ->and($payload)->toContain('SK3112000000198742637541')
        ->and($payload)->toContain('EUR121.50');
});

it('serves the QR as an SVG data uri over the endpoint', function (): void {
    $user = createUser();
    $invoice = qrSupplierInvoice($user, [
        'vendor_account_number' => '19-2000145399',
        'vendor_bank_code' => '0800',
    ]);

    $response = $this->actingAs($user)->getJson("/api/v1/supplier-invoices/{$invoice->id}/payment-qr");

    $response->assertOk();
    expect($response->json('data_uri'))->toStartWith('data:image/svg+xml');
});

it('returns 422 when the invoice has no stored account', function (): void {
    $user = createUser();
    $invoice = qrSupplierInvoice($user);

    $this->actingAs($user)->getJson("/api/v1/supplier-invoices/{$invoice->id}/payment-qr")->assertStatus(422);
});

it('returns 422 for an unsupported currency', function (): void {
    $user = createUser();
    $invoice = qrSupplierInvoice($user, [
        'currency' => Currency::USD->value,
        'vendor_iban' => 'CZ6508000000192000145399',
    ]);

    $this->actingAs($user)->getJson("/api/v1/supplier-invoices/{$invoice->id}/payment-qr")->assertStatus(422);
});

it('denies the QR of another account\'s invoice', function (): void {
    $owner = createUser();
    $invoice = qrSupplierInvoice($owner, [
        'vendor_account_number' => '19-2000145399',
        'vendor_bank_code' => '0800',
    ]);

    $this->actingAs(createUser())->getJson("/api/v1/supplier-invoices/{$invoice->id}/payment-qr")->assertNotFound();
});
