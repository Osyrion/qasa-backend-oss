<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function inboxItemFor(User $user, array $overrides = []): InvoiceInboxItem
{
    $path = 'supplier-invoices/inbox/'.$user->id.'/'.Str::uuid()->toString().'.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 stub-pdf-contents');

    return InvoiceInboxItem::factory()->create([
        'user_id' => $user->id,
        'path' => $path,
        ...$overrides,
    ]);
}

it('only lists the account\'s own inbox items', function (): void {
    $user = createUser();
    $other = createUser();

    inboxItemFor($user);
    inboxItemFor($other);

    $response = $this->actingAs($user)->getJson('/api/v1/invoice-inbox');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('converts a pending inbox item into a supplier invoice', function (): void {
    $user = createSupplierInvoiceOwner();
    $vendor = vendorClientFor($user);
    $item = inboxItemFor($user);

    $response = $this->actingAs($user)->postJson(
        "/api/v1/invoice-inbox/{$item->id}/convert",
        supplierInvoicePayload($vendor->id),
    );

    $response->assertCreated();

    $item->refresh();
    expect($item->status)->toBe('imported')
        ->and($item->supplier_invoice_id)->toBe($response->json('id'));

    $this->assertDatabaseHas('supplier_invoices', [
        'id' => $response->json('id'),
        'client_id' => $vendor->id,
        'user_id' => $user->id,
    ]);
});

it('carries an OCR-suggested account over to the supplier invoice as ocr-sourced', function (): void {
    $user = createSupplierInvoiceOwner();
    $vendor = vendorClientFor($user);
    $item = inboxItemFor($user, [
        'suggestions' => [
            'supplier_invoice_number' => 'INV-1',
            'account_number' => '19-2000145399',
            'bank_code' => '0800',
        ],
    ]);

    $response = $this->actingAs($user)->postJson(
        "/api/v1/invoice-inbox/{$item->id}/convert",
        supplierInvoicePayload($vendor->id, [
            'vendor_account_number' => '19-2000145399',
            'vendor_bank_code' => '0800',
        ]),
    );

    $response->assertCreated();

    $this->assertDatabaseHas('supplier_invoices', [
        'id' => $response->json('id'),
        'vendor_account_number' => '19-2000145399',
        'vendor_bank_code' => '0800',
        'account_source' => 'ocr',
    ]);
});

it('keeps a retyped account as manual when converting', function (): void {
    $user = createSupplierInvoiceOwner();
    $vendor = vendorClientFor($user);
    $item = inboxItemFor($user, [
        'suggestions' => ['account_number' => '19-2000145399', 'bank_code' => '0800'],
    ]);

    $response = $this->actingAs($user)->postJson(
        "/api/v1/invoice-inbox/{$item->id}/convert",
        supplierInvoicePayload($vendor->id, [
            'vendor_account_number' => '999999999',
            'vendor_bank_code' => '0300',
        ]),
    );

    $response->assertCreated();

    $this->assertDatabaseHas('supplier_invoices', [
        'id' => $response->json('id'),
        'account_source' => 'manual',
    ]);
});

it('rejects converting an already processed inbox item', function (): void {
    $user = createUser();
    $vendor = vendorClientFor($user);
    $item = inboxItemFor($user, ['status' => 'imported']);

    $response = $this->actingAs($user)->postJson(
        "/api/v1/invoice-inbox/{$item->id}/convert",
        supplierInvoicePayload($vendor->id),
    );

    $response->assertStatus(422);
});

it('ignores a pending inbox item', function (): void {
    $user = createUser();
    $item = inboxItemFor($user);

    $this->actingAs($user)->postJson("/api/v1/invoice-inbox/{$item->id}/ignore")->assertOk();

    expect($item->refresh()->status)->toBe('ignored');
});

it('rejects ignoring an already ignored inbox item', function (): void {
    $user = createUser();
    $item = inboxItemFor($user, ['status' => 'ignored']);

    $this->actingAs($user)->postJson("/api/v1/invoice-inbox/{$item->id}/ignore")->assertStatus(422);
});

it('downloads the original document', function (): void {
    $user = createUser();
    $item = inboxItemFor($user);

    $response = $this->actingAs($user)->get("/api/v1/invoice-inbox/{$item->id}/download");

    $response->assertOk();
    $response->assertHeader('content-disposition');
});

it('deletes an inbox item', function (): void {
    $user = createUser();
    $item = inboxItemFor($user);

    $this->actingAs($user)->deleteJson("/api/v1/invoice-inbox/{$item->id}")->assertNoContent();
    $this->assertSoftDeleted('invoice_inbox_items', ['id' => $item->id]);
});

it('does not let a user access another account inbox item', function (): void {
    $victim = createUser();
    $victimItem = inboxItemFor($victim);

    $attacker = createUser();

    $this->actingAs($attacker)->getJson("/api/v1/invoice-inbox/{$victimItem->id}")->assertNotFound();
    $this->actingAs($attacker)->get("/api/v1/invoice-inbox/{$victimItem->id}/download")->assertNotFound();
    $this->actingAs($attacker)->postJson("/api/v1/invoice-inbox/{$victimItem->id}/ignore")->assertNotFound();
    $this->actingAs($attacker)->deleteJson("/api/v1/invoice-inbox/{$victimItem->id}")->assertNotFound();
});
