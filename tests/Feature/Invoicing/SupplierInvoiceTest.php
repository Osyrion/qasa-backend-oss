<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use Illuminate\Support\Str;

it('creates a supplier invoice with a generated internal number and totals from vat lines', function (): void {
    $user = createSupplierInvoiceOwner();
    $vendor = vendorClientFor($user);

    $response = $this->actingAs($user)->postJson(
        '/api/v1/supplier-invoices',
        supplierInvoicePayload($vendor->id),
    );

    $response->assertCreated();

    expect($response->json('status'))->toBe('draft')
        ->and($response->json('internal_number'))->toStartWith('DF-'.now()->format('Y').'-')
        ->and((float) $response->json('subtotal'))->toBe(100.0)
        ->and((float) $response->json('vat_amount'))->toBe(23.0)
        ->and((float) $response->json('total'))->toBe(123.0);

    $this->assertDatabaseHas('supplier_invoices', [
        'id' => $response->json('id'),
        'client_id' => $vendor->id,
        'user_id' => $user->id,
    ]);
});

it('generates sequential internal numbers for consecutive creates', function (): void {
    $user = createSupplierInvoiceOwner();
    $vendor = vendorClientFor($user);

    $first = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($vendor->id));
    $second = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($vendor->id));

    $first->assertCreated();
    $second->assertCreated();

    $firstNumber = (int) Str::afterLast((string) $first->json('internal_number'), '-');
    $secondNumber = (int) Str::afterLast((string) $second->json('internal_number'), '-');

    expect($secondNumber)->toBe($firstNumber + 1);
});

it('rejects a client that is not a vendor', function (): void {
    $user = createUser();
    $customer = Client::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson(
        '/api/v1/supplier-invoices',
        supplierInvoicePayload($customer->id),
    );

    $response->assertStatus(422);
    expect($response->json('message'))->not->toBeNull();
});

it('only allows updating a draft supplier invoice', function (): void {
    $user = createSupplierInvoiceOwner();
    $vendor = vendorClientFor($user);

    $supplierInvoice = SupplierInvoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $vendor->id,
    ]);

    $updateOk = $this->actingAs($user)->putJson(
        "/api/v1/supplier-invoices/{$supplierInvoice->id}",
        supplierInvoicePayload($vendor->id, ['vat_lines' => [['vat_rate' => 19, 'base' => 200, 'vat_amount' => 20]]]),
    );

    $updateOk->assertOk();
    expect((float) $updateOk->json('total'))->toBe(220.0);

    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$supplierInvoice->id}/status", [
        'status' => 'received',
    ])->assertOk();

    $this->actingAs($user)->putJson(
        "/api/v1/supplier-invoices/{$supplierInvoice->id}",
        supplierInvoicePayload($vendor->id),
    )->assertForbidden();
});

it('walks the valid status transitions and freezes the vendor snapshot on receive', function (): void {
    $user = createUser();
    $vendor = vendorClientFor($user);

    $supplierInvoice = SupplierInvoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $vendor->id,
    ]);

    $received = $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$supplierInvoice->id}/status", [
        'status' => 'received',
    ]);
    $received->assertOk();
    expect($received->json('vendor_snapshot'))->not->toBeNull()
        ->and($received->json('vendor_snapshot.email'))->toBe($vendor->email);

    $paid = $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$supplierInvoice->id}/status", [
        'status' => 'paid',
    ]);
    $paid->assertOk();
    expect($paid->json('paid_at'))->toBe(now()->toDateString())
        ->and($paid->json('status'))->toBe('paid');
});

it('rejects invalid status transitions', function (): void {
    $user = createUser();
    $vendor = vendorClientFor($user);

    $supplierInvoice = SupplierInvoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $vendor->id,
    ]);

    // draft -> paid is not a valid direct transition
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$supplierInvoice->id}/status", [
        'status' => 'paid',
    ])->assertStatus(422);

    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$supplierInvoice->id}/status", [
        'status' => 'received',
    ])->assertOk();

    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$supplierInvoice->id}/status", [
        'status' => 'paid',
    ])->assertOk();

    // paid is terminal
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$supplierInvoice->id}/status", [
        'status' => 'received',
    ])->assertStatus(422);
});

it('deletes only a draft supplier invoice', function (): void {
    $user = createUser();
    $vendor = vendorClientFor($user);

    $draft = SupplierInvoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $vendor->id,
    ]);

    $this->actingAs($user)->deleteJson("/api/v1/supplier-invoices/{$draft->id}")->assertNoContent();
    $this->assertSoftDeleted('supplier_invoices', ['id' => $draft->id]);

    $received = SupplierInvoice::factory()->received()->create([
        'user_id' => $user->id,
        'client_id' => $vendor->id,
    ]);

    $this->actingAs($user)->deleteJson("/api/v1/supplier-invoices/{$received->id}")->assertForbidden();
    $this->assertDatabaseHas('supplier_invoices', ['id' => $received->id, 'deleted_at' => null]);
});

it('does not let a user access another account supplier invoice', function (): void {
    $victim = createUser();
    $victimVendor = vendorClientFor($victim);

    $victimInvoice = SupplierInvoice::factory()->draft()->create([
        'user_id' => $victim->id,
        'client_id' => $victimVendor->id,
    ]);

    $attacker = createUser();

    $this->actingAs($attacker)
        ->getJson("/api/v1/supplier-invoices/{$victimInvoice->id}")
        ->assertNotFound();

    $this->actingAs($attacker)
        ->deleteJson("/api/v1/supplier-invoices/{$victimInvoice->id}")
        ->assertNotFound();
});
