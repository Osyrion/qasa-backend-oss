<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Enums\Currency;

function paymentOrderVendor(User $user): Client
{
    return Client::factory()->vendor()->create(['user_id' => $user->id]);
}

function paymentOrderBankAccount(User $user, Currency $currency = Currency::CZK): BankAccount
{
    return BankAccount::factory()->currency($currency)->create([
        'user_id' => $user->id,
        'account_number' => '123456789/0100',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function payableSupplierInvoice(User $user, Client $vendor, array $overrides = []): SupplierInvoice
{
    return SupplierInvoice::factory()->received()->create(array_merge([
        'user_id' => $user->id,
        'client_id' => $vendor->id,
        'currency' => Currency::CZK->value,
        'vendor_account_number' => '19-2000145399',
        'vendor_bank_code' => '0800',
        'variable_symbol' => '20260001',
        'subtotal' => 100,
        'vat_amount' => 21,
        'total' => 121,
    ], $overrides));
}

it('creates a payment order with frozen rows and marks invoices as handed to payment', function (): void {
    $user = createUser();
    $vendor = paymentOrderVendor($user);
    $account = paymentOrderBankAccount($user);
    $first = payableSupplierInvoice($user, $vendor, ['total' => 121]);
    $second = payableSupplierInvoice($user, $vendor, ['total' => 200, 'vendor_iban' => 'CZ6508000000192000145399']);

    $response = $this->actingAs($user)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $account->id,
        'due_date' => now()->addDays(3)->toDateString(),
        'constant_symbol' => '0308',
        'supplier_invoice_ids' => [$first->id, $second->id],
    ]);

    $response->assertCreated();

    expect($response->json('due_date_adjusted'))->toBeFalse()
        ->and($response->json('data.items_count'))->toBe(2)
        ->and((float) $response->json('data.total_amount'))->toBe(321.0)
        ->and($response->json('data.currency'))->toBe('CZK')
        ->and($response->json('data.payer_snapshot.account_number'))->toBe('123456789/0100')
        ->and($response->json('data.items.0.account_number'))->toBe('19-2000145399')
        ->and($response->json('data.items.0.variable_symbol'))->toBe('20260001')
        ->and($response->json('data.marked_paid'))->toBeFalse();

    expect($first->refresh()->handed_to_payment_at)->not->toBeNull()
        ->and($first->status)->toBe('received')
        ->and($second->refresh()->handed_to_payment_at)->not->toBeNull();
});

it('optionally marks the invoices as paid', function (): void {
    $user = createUser();
    $vendor = paymentOrderVendor($user);
    $account = paymentOrderBankAccount($user);
    $invoice = payableSupplierInvoice($user, $vendor);

    $response = $this->actingAs($user)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $account->id,
        'due_date' => now()->addDay()->toDateString(),
        'supplier_invoice_ids' => [$invoice->id],
        'mark_paid' => true,
    ]);

    $response->assertCreated();

    expect($response->json('data.marked_paid'))->toBeTrue()
        ->and($invoice->refresh()->status)->toBe('paid')
        ->and($invoice->paid_at)->not->toBeNull();
});

it('bumps a past due date to today and reports it', function (): void {
    $user = createUser();
    $vendor = paymentOrderVendor($user);
    $account = paymentOrderBankAccount($user);
    $invoice = payableSupplierInvoice($user, $vendor);

    $response = $this->actingAs($user)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $account->id,
        'due_date' => now()->subDays(5)->toDateString(),
        'supplier_invoice_ids' => [$invoice->id],
    ]);

    $response->assertCreated();

    expect($response->json('due_date_adjusted'))->toBeTrue()
        ->and($response->json('data.due_date'))->toBe(now()->toDateString());
});

it('rejects a foreign supplier invoice with 404', function (): void {
    $user = createUser();
    $stranger = createUser();
    $account = paymentOrderBankAccount($user);
    $foreign = payableSupplierInvoice($stranger, paymentOrderVendor($stranger));

    $this->actingAs($user)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $account->id,
        'due_date' => now()->toDateString(),
        'supplier_invoice_ids' => [$foreign->id],
    ])->assertNotFound();
});

it('rejects a foreign bank account with 404', function (): void {
    $user = createUser();
    $stranger = createUser();
    $invoice = payableSupplierInvoice($user, paymentOrderVendor($user));
    $foreignAccount = paymentOrderBankAccount($stranger);

    $this->actingAs($user)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $foreignAccount->id,
        'due_date' => now()->toDateString(),
        'supplier_invoice_ids' => [$invoice->id],
    ])->assertNotFound();
});

it('rejects an invoice in another currency than the payer account', function (): void {
    $user = createUser();
    $vendor = paymentOrderVendor($user);
    $account = paymentOrderBankAccount($user); // CZK
    $invoice = payableSupplierInvoice($user, $vendor, ['currency' => Currency::EUR->value]);

    $response = $this->actingAs($user)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $account->id,
        'due_date' => now()->toDateString(),
        'supplier_invoice_ids' => [$invoice->id],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->not->toBeNull();
});

it('rejects an invoice without a vendor account', function (): void {
    $user = createUser();
    $vendor = paymentOrderVendor($user);
    $account = paymentOrderBankAccount($user);
    $invoice = payableSupplierInvoice($user, $vendor, [
        'vendor_account_number' => null,
        'vendor_bank_code' => null,
    ]);

    $this->actingAs($user)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $account->id,
        'due_date' => now()->toDateString(),
        'supplier_invoice_ids' => [$invoice->id],
    ])->assertStatus(422);
});

it('rejects invoices that are not payable (draft or paid)', function (string $status): void {
    $user = createUser();
    $vendor = paymentOrderVendor($user);
    $account = paymentOrderBankAccount($user);
    $invoice = payableSupplierInvoice($user, $vendor, ['status' => $status]);

    $this->actingAs($user)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $account->id,
        'due_date' => now()->toDateString(),
        'supplier_invoice_ids' => [$invoice->id],
    ])->assertStatus(422);
})->with(['draft', 'paid', 'cancelled']);

it('lists payment order history and shows detail with rows', function (): void {
    $user = createUser();
    $vendor = paymentOrderVendor($user);
    $account = paymentOrderBankAccount($user);
    $invoice = payableSupplierInvoice($user, $vendor);

    $created = $this->actingAs($user)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $account->id,
        'due_date' => now()->toDateString(),
        'supplier_invoice_ids' => [$invoice->id],
    ])->json('data.id');

    $index = $this->actingAs($user)->getJson('/api/v1/payment-orders');
    $index->assertOk();
    expect($index->json('data.0.id'))->toBe($created)
        ->and($index->json('data.0.items_count'))->toBe(1);

    $show = $this->actingAs($user)->getJson("/api/v1/payment-orders/{$created}");
    $show->assertOk();
    expect($show->json('data.items'))->toHaveCount(1)
        ->and($show->json('data.items.0.supplier_invoice_id'))->toBe($invoice->id);
});

it('denies access to another account\'s payment order', function (): void {
    $owner = createUser();
    $vendor = paymentOrderVendor($owner);
    $account = paymentOrderBankAccount($owner);
    $invoice = payableSupplierInvoice($owner, $vendor);

    $id = $this->actingAs($owner)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $account->id,
        'due_date' => now()->toDateString(),
        'supplier_invoice_ids' => [$invoice->id],
    ])->json('data.id');

    $stranger = createUser();

    $this->actingAs($stranger)->getJson("/api/v1/payment-orders/{$id}")->assertNotFound();
    $this->actingAs($stranger)->deleteJson("/api/v1/payment-orders/{$id}")->assertNotFound();
});

it('clears the handed flag on delete only where no other live batch holds the invoice', function (): void {
    $user = createUser();
    $vendor = paymentOrderVendor($user);
    $account = paymentOrderBankAccount($user);
    $shared = payableSupplierInvoice($user, $vendor);
    $single = payableSupplierInvoice($user, $vendor);

    $firstOrder = $this->actingAs($user)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $account->id,
        'due_date' => now()->toDateString(),
        'supplier_invoice_ids' => [$shared->id, $single->id],
    ])->json('data.id');

    $this->actingAs($user)->postJson('/api/v1/payment-orders', [
        'bank_account_id' => $account->id,
        'due_date' => now()->toDateString(),
        'supplier_invoice_ids' => [$shared->id],
    ])->assertCreated();

    $this->actingAs($user)->deleteJson("/api/v1/payment-orders/{$firstOrder}")->assertNoContent();

    expect(PaymentOrder::withTrashed()->find($firstOrder)?->trashed())->toBeTrue()
        ->and($shared->refresh()->handed_to_payment_at)->not->toBeNull()
        ->and($single->refresh()->handed_to_payment_at)->toBeNull();
});
