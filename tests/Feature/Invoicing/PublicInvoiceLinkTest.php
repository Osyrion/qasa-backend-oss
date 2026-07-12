<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Mail\InvoiceEmail;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Support\Facades\Mail;

/**
 * @param  array<string, mixed>  $invoiceAttributes
 * @return array{0: User, 1: Invoice}
 */
function publicLinkInvoice(array $invoiceAttributes = []): array
{
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => 'klient@example.com']);
    $bankAccount = BankAccount::factory()->create(['user_id' => $user->id, 'currency' => 'CZK']);

    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'currency' => 'CZK',
        'exchange_rate_snapshot' => null,
        'bank_account_id' => $bankAccount->id,
        'bank_account_snapshot' => $bankAccount->toSnapshot(),
        'supplier_snapshot' => ['name' => 'Dodávateľ s.r.o.', 'email' => 'dodavatel@example.com'],
        'client_snapshot' => ['name' => 'Klient s.r.o.', 'email' => 'klient@example.com'],
        ...$invoiceAttributes,
    ]);

    $invoice->items()->create([
        'description' => 'Konzultácia',
        'quantity' => 1,
        'unit' => 'ks',
        'unit_price' => 1000,
        'vat_rate' => 20,
        'vat_amount' => 200,
        'total_excl_vat' => 1000,
        'total_incl_vat' => 1200,
        'sort_order' => 0,
    ]);

    $invoice->recalculateTotals()->save();

    return [$user, $invoice->refresh()];
}

// ── Generation ──────────────────────────────────────────────────────────────

it('rejects creating a public link for a draft invoice', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->draft()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/public-link")
        ->assertUnprocessable();

    expect($invoice->refresh()->public_token)->toBeNull();
});

it('is idempotent when creating a public link twice', function (): void {
    [$user, $invoice] = publicLinkInvoice();

    $first = $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/public-link")->assertOk();
    $second = $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/public-link")->assertOk();

    expect($first->json('token'))->toBe($second->json('token'));
});

it('regenerates the token and invalidates the old one', function (): void {
    [$user, $invoice] = publicLinkInvoice();

    $first = $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/public-link")->assertOk();
    $oldToken = $first->json('token');

    $second = $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/public-link", ['regenerate' => true])
        ->assertOk();

    expect($second->json('token'))->not->toBe($oldToken);

    $this->getJson("/api/v1/public/invoices/{$oldToken}")->assertNotFound();
});

it('revokes a public link', function (): void {
    [$user, $invoice] = publicLinkInvoice();

    $created = $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/public-link")->assertOk();
    $token = $created->json('token');

    $this->actingAs($user)->deleteJson("/api/v1/invoices/{$invoice->id}/public-link")->assertNoContent();

    expect($invoice->refresh()->public_token)->toBeNull();

    $this->getJson("/api/v1/public/invoices/{$token}")->assertNotFound();
});

// ── Public payload ────────────────────────────────────────────────────────────

it('serves the public payload without authentication, built from snapshots', function (): void {
    [$user, $invoice] = publicLinkInvoice();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/public-link")->assertOk();
    $token = $invoice->refresh()->public_token;

    // A profile edit after issuance must not leak into the frozen payload.
    $user->update(['name' => 'Zmenené', 'surname' => 'Meno']);

    $response = $this->getJson("/api/v1/public/invoices/{$token}")->assertOk();

    $response->assertJsonPath('supplier.name', 'Dodávateľ s.r.o.')
        ->assertJsonPath('client.name', 'Klient s.r.o.')
        ->assertJsonPath('invoice_number', $invoice->invoice_number)
        ->assertJsonCount(1, 'items');

    $json = $response->json();
    expect($json)->not->toHaveKeys(['user_id', 'client_id', 'id']);
});

it('maps public_status and includes a QR code while unpaid', function (): void {
    [$user, $invoice] = publicLinkInvoice();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/public-link")->assertOk();
    $token = $invoice->refresh()->public_token;

    $response = $this->getJson("/api/v1/public/invoices/{$token}")->assertOk();

    $response->assertJsonPath('public_status', 'unpaid');
    expect($response->json('payment.qr_svg'))->not->toBeNull()
        ->and((float) $response->json('payment.balance'))->toBe(1200.0);
});

it('omits the QR code once fully paid', function (): void {
    [$user, $invoice] = publicLinkInvoice();
    $invoice->payments()->create(['amount' => 1200, 'paid_at' => now()]);
    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/public-link")->assertOk();
    $token = $invoice->refresh()->public_token;

    $response = $this->getJson("/api/v1/public/invoices/{$token}")->assertOk();

    $response->assertJsonPath('public_status', 'paid');
    expect($response->json('payment.qr_svg'))->toBeNull();
});

// ── Tracking ──────────────────────────────────────────────────────────────────

it('tracks the first view once and increments the view count on each open', function (): void {
    [$user, $invoice] = publicLinkInvoice();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/public-link")->assertOk();
    $token = $invoice->refresh()->public_token;

    $this->getJson("/api/v1/public/invoices/{$token}")->assertOk();
    $firstViewedAt = $invoice->refresh()->public_first_viewed_at;

    expect($firstViewedAt)->not->toBeNull()
        ->and($invoice->public_view_count)->toBe(1);

    $this->getJson("/api/v1/public/invoices/{$token}")->assertOk();

    $secondViewedAt = $invoice->refresh()->public_first_viewed_at;

    expect($secondViewedAt !== null && $firstViewedAt !== null && $secondViewedAt->equalTo($firstViewedAt))->toBeTrue()
        ->and($invoice->public_view_count)->toBe(2);
});

it('does not track a view when downloading the PDF', function (): void {
    [$user, $invoice] = publicLinkInvoice();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/public-link")->assertOk();
    $token = $invoice->refresh()->public_token;

    $this->get("/api/v1/public/invoices/{$token}/pdf")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($invoice->refresh()->public_view_count)->toBe(0)
        ->and($invoice->public_first_viewed_at)->toBeNull();
});

// ── Security ──────────────────────────────────────────────────────────────────

it('returns 404 for an unknown token', function (): void {
    $this->getJson('/api/v1/public/invoices/does-not-exist')->assertNotFound();
    $this->get('/api/v1/public/invoices/does-not-exist/pdf')->assertNotFound();
});

it('throttles the public endpoints per IP', function (): void {
    [$user, $invoice] = publicLinkInvoice();
    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/public-link")->assertOk();
    $token = $invoice->refresh()->public_token;

    for ($i = 0; $i < 30; $i++) {
        $this->getJson("/api/v1/public/invoices/{$token}")->assertOk();
    }

    $this->getJson("/api/v1/public/invoices/{$token}")->assertStatus(429);
});

it('keeps a cancelled invoice publicly viewable with a cancelled status', function (): void {
    [$user, $invoice] = publicLinkInvoice(['status' => 'cancelled']);
    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/public-link")->assertOk();
    $token = $invoice->refresh()->public_token;

    $this->getJson("/api/v1/public/invoices/{$token}")
        ->assertOk()
        ->assertJsonPath('public_status', 'cancelled');
});

// ── Email integration ──────────────────────────────────────────────────────────

it('includes the public link in the invoice email body', function (): void {
    Mail::fake();

    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => 'klient@example.com']);
    $invoice = Invoice::factory()->draft()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/email")->assertOk();

    $token = (string) $invoice->refresh()->public_token;

    expect($token)->not->toBe('');

    Mail::assertQueued(
        InvoiceEmail::class,
        fn (InvoiceEmail $mail): bool => $mail->publicUrl !== null
            && str_contains($mail->publicUrl, $token),
    );
});

it('omits the public link when disabled by config', function (): void {
    config(['invoicing.public_link_in_emails' => false]);
    Mail::fake();

    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => 'klient@example.com']);
    $invoice = Invoice::factory()->draft()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/email")->assertOk();

    expect($invoice->refresh()->public_token)->toBeNull();

    Mail::assertQueued(
        InvoiceEmail::class,
        fn (InvoiceEmail $mail): bool => $mail->publicUrl === null,
    );
});
