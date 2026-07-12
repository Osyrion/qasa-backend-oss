<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Enums\Currency;

/**
 * @return array{0: User, 1: SupplierInvoice, 2: string} owner, invoice, order id
 */
function exportedPaymentOrder(Currency $currency = Currency::CZK): array
{
    $user = createUser();
    $vendor = Client::factory()->vendor()->create(['user_id' => $user->id]);
    $account = BankAccount::factory()->currency($currency)->create([
        'user_id' => $user->id,
        'account_number' => '123456789/0100',
    ]);

    $invoice = SupplierInvoice::factory()->received()->create([
        'user_id' => $user->id,
        'client_id' => $vendor->id,
        'currency' => $currency->value,
        'supplier_invoice_number' => 'FA-2026-777',
        'vendor_account_number' => '19-2000145399',
        'vendor_bank_code' => '0800',
        'variable_symbol' => '20260001',
        'total' => 121,
    ]);

    $orderId = test()->actingAs($user)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $account->id,
        'due_date' => now()->addDay()->toDateString(),
        'supplier_invoice_ids' => [$invoice->id],
    ])->assertCreated()->json('data.id');

    return [$user, $invoice, $orderId];
}

it('exports CSV from the frozen snapshot, unaffected by later invoice edits', function (): void {
    [$user, $invoice, $orderId] = exportedPaymentOrder();

    $before = $this->actingAs($user)->get("/api/v1/payment-orders/{$orderId}/export/csv");
    $before->assertOk();
    $before->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect($before->getContent())->toContain('FA-2026-777')
        ->and($before->getContent())->toContain('19-2000145399/0800')
        ->and($before->getContent())->toContain('20260001');

    // Later edits to the invoice must not change the export.
    $invoice->update(['vendor_account_number' => '999999999', 'vendor_bank_code' => '0300', 'total' => 999]);

    $after = $this->actingAs($user)->get("/api/v1/payment-orders/{$orderId}/export/csv");

    expect($after->getContent())->toBe($before->getContent());
});

it('exports the ABO file for a CZK batch with domestic accounts', function (): void {
    [$user, , $orderId] = exportedPaymentOrder();

    $response = $this->actingAs($user)->get("/api/v1/payment-orders/{$orderId}/export/abo");

    $response->assertOk();
    $response->assertHeader('Content-Disposition', 'attachment; filename="prikaz_'.now()->addDay()->format('Y-m-d').'.kpc"');

    expect($response->getContent())->toStartWith('UHL1')
        ->and($response->getContent())->toContain('19-2000145399');
});

it('rejects ABO export for an EUR batch', function (): void {
    [$user, , $orderId] = exportedPaymentOrder(Currency::EUR);

    $response = $this->actingAs($user)->get("/api/v1/payment-orders/{$orderId}/export/abo");

    $response->assertStatus(422);
    expect($response->json('message'))->not->toBeNull();
});

it('exports a PDF overview', function (): void {
    [$user, , $orderId] = exportedPaymentOrder();

    $response = $this->actingAs($user)->get("/api/v1/payment-orders/{$orderId}/export/pdf");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF');
});

it('rejects an unknown export format with 404', function (): void {
    [$user, , $orderId] = exportedPaymentOrder();

    $this->actingAs($user)->get("/api/v1/payment-orders/{$orderId}/export/xml")->assertNotFound();
});

it('denies exporting another account\'s payment order', function (): void {
    [, , $orderId] = exportedPaymentOrder();
    $stranger = createUser();

    $this->actingAs($stranger)->get("/api/v1/payment-orders/{$orderId}/export/csv")->assertNotFound();
});
