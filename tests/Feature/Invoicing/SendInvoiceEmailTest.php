<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Mail\InvoiceEmail;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Support\Facades\Mail;

/**
 * @param  array<string, mixed>  $invoiceAttributes
 * @param  array<string, mixed>  $clientAttributes
 * @return array{0: User, 1: Invoice, 2: Client}
 */
function emailableInvoice(array $invoiceAttributes = [], array $clientAttributes = []): array
{
    $user = createUser();
    $client = Client::factory()->create([
        'user_id' => $user->id,
        'email' => 'klient@example.com',
        'locale' => 'sk',
        ...$clientAttributes,
    ]);

    $invoice = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'currency' => 'CZK',
        'exchange_rate_snapshot' => null,
        ...$invoiceAttributes,
    ]);

    return [$user, $invoice, $client];
}

it('issues a draft invoice and queues the email to the client', function (): void {
    Mail::fake();

    [$user, $invoice, $client] = emailableInvoice();

    $response = $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/email");

    $response->assertOk();

    $invoice->refresh();

    expect($invoice->status)->toBe('sent')
        ->and($invoice->supplier_snapshot)->not->toBeNull()
        ->and($invoice->emailed_at)->not->toBeNull()
        ->and($invoice->emailed_to)->toBe('klient@example.com')
        ->and($response->json('status'))->toBe('sent')
        ->and($response->json('emailed_at'))->not->toBeNull()
        ->and($response->json('emailed_to'))->toBe('klient@example.com');

    Mail::assertQueued(
        InvoiceEmail::class,
        fn (InvoiceEmail $mail): bool => $mail->hasTo('klient@example.com')
            && $mail->invoice->is($invoice),
    );
});

it('emails an already sent invoice without changing its status', function (): void {
    Mail::fake();

    [$user, $invoice] = emailableInvoice([
        'status' => 'sent',
        'client_snapshot' => ['email' => 'snapshot@example.com'],
    ]);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/email")->assertOk();

    expect($invoice->refresh()->status)->toBe('sent');

    Mail::assertQueued(InvoiceEmail::class);
});

it('prefers the frozen client snapshot email over the live client email', function (): void {
    Mail::fake();

    [$user, $invoice] = emailableInvoice([
        'status' => 'sent',
        'client_snapshot' => ['email' => 'snapshot@example.com'],
    ]);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/email")->assertOk();

    expect($invoice->refresh()->emailed_to)->toBe('snapshot@example.com');

    Mail::assertQueued(
        InvoiceEmail::class,
        fn (InvoiceEmail $mail): bool => $mail->hasTo('snapshot@example.com'),
    );
});

it('emails a paid invoice without changing its status', function (): void {
    Mail::fake();

    [$user, $invoice] = emailableInvoice(['status' => 'paid']);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/email")->assertOk();

    expect($invoice->refresh()->status)->toBe('paid');

    Mail::assertQueued(InvoiceEmail::class);
});

it('rejects emailing a cancelled invoice', function (): void {
    Mail::fake();

    [$user, $invoice] = emailableInvoice(['status' => 'cancelled']);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/email")
        ->assertUnprocessable();

    Mail::assertNothingQueued();
});

it('honours recipient override, cc and a custom message', function (): void {
    Mail::fake();

    [$user, $invoice] = emailableInvoice();

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/email", [
        'to' => 'override@example.com',
        'cc' => ['kopia@example.com'],
        'message' => 'Vlastný sprievodný text.',
    ])->assertOk();

    expect($invoice->refresh()->emailed_to)->toBe('override@example.com')
        ->and($invoice->emailed_cc)->toBe(['kopia@example.com']);

    Mail::assertQueued(
        InvoiceEmail::class,
        fn (InvoiceEmail $mail): bool => $mail->hasTo('override@example.com')
            && $mail->hasCc('kopia@example.com')
            && $mail->customMessage === 'Vlastný sprievodný text.',
    );
});

it('marks the invoice when the queued email job permanently fails', function (): void {
    [, $invoice] = emailableInvoice(['status' => 'sent']);

    (new InvoiceEmail($invoice))->failed(new RuntimeException('SMTP down'));

    expect($invoice->refresh()->email_failed_at)->not->toBeNull();
});

it('clears a previous email failure on resend', function (): void {
    Mail::fake();

    [$user, $invoice] = emailableInvoice([
        'status' => 'sent',
        'email_failed_at' => now()->subDay(),
    ]);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/email")->assertOk();

    expect($invoice->refresh()->email_failed_at)->toBeNull();
});

it('validates the request body', function (): void {
    Mail::fake();

    [$user, $invoice] = emailableInvoice();

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/email", [
        'to' => 'not-an-email',
        'cc' => ['also-not-an-email'],
        'message' => str_repeat('x', 2001),
    ])->assertUnprocessable()->assertJsonValidationErrors(['to', 'cc.0', 'message']);

    Mail::assertNothingQueued();
});

it('rejects a client without an email and leaves the draft untouched', function (): void {
    Mail::fake();

    [$user, $invoice] = emailableInvoice(clientAttributes: ['email' => null]);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/email")
        ->assertUnprocessable();

    expect($invoice->refresh()->status)->toBe('draft')
        ->and($invoice->emailed_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('is invisible to another account', function (): void {
    Mail::fake();

    [, $invoice] = emailableInvoice();
    $stranger = createUser();

    $this->actingAs($stranger)
        ->postJson("/api/v1/invoices/{$invoice->id}/email")
        ->assertNotFound();

    Mail::assertNothingQueued();
});

it('localizes the email to the client locale', function (): void {
    Mail::fake();

    [$user, $invoice] = emailableInvoice(clientAttributes: ['locale' => 'cs']);

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/email")->assertOk();

    Mail::assertQueued(
        InvoiceEmail::class,
        fn (InvoiceEmail $mail): bool => $mail->locale === 'cs',
    );
});
