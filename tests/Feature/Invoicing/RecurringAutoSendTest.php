<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Mail\InvoiceEmail;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use Illuminate\Support\Facades\Mail;

/**
 * @param  array<string, mixed>  $templateAttributes
 * @param  array<string, mixed>  $clientAttributes
 */
function autoSendTemplate(array $templateAttributes = [], array $clientAttributes = []): RecurringInvoiceTemplate
{
    $user = createUser(['invoice_prefix' => 'FA', 'vat_status' => 'payer']);
    $client = Client::factory()->create([
        'user_id' => $user->id,
        'email' => 'klient@example.com',
        'locale' => 'sk',
        ...$clientAttributes,
    ]);

    $template = RecurringInvoiceTemplate::factory()->dueToday()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'currency' => 'CZK',
        'auto_send' => true,
        ...$templateAttributes,
    ]);

    $template->items()->create([
        'description' => 'Hosting',
        'quantity' => 1,
        'unit' => 'ks',
        'unit_price' => 1000,
        'vat_rate' => 21,
        'sort_order' => 0,
    ]);

    return $template->refresh();
}

it('issues and queues the email for an auto_send template', function (): void {
    Mail::fake();

    autoSendTemplate();

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    $invoice = Invoice::withoutGlobalScope('user')->firstOrFail();

    expect($invoice->status)->toBe('sent')
        ->and($invoice->supplier_snapshot)->not->toBeNull()
        ->and($invoice->emailed_at)->not->toBeNull()
        ->and($invoice->emailed_to)->toBe('klient@example.com');

    Mail::assertQueued(
        InvoiceEmail::class,
        fn (InvoiceEmail $mail): bool => $mail->hasTo('klient@example.com'),
    );
});

it('leaves invoices as draft without the auto_send flag', function (): void {
    Mail::fake();

    autoSendTemplate(['auto_send' => false]);

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    $invoice = Invoice::withoutGlobalScope('user')->firstOrFail();

    expect($invoice->status)->toBe('draft')
        ->and($invoice->emailed_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('auto-sends only the newest invoice after a catch-up', function (): void {
    Mail::fake();

    autoSendTemplate([
        'first_issue_date' => today()->subMonths(2)->toDateString(),
        'next_run_date' => today()->subMonths(2)->toDateString(),
        'day_of_month' => today()->subMonths(2)->day,
    ]);

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    $invoices = Invoice::withoutGlobalScope('user')->orderBy('issued_at')->get();

    expect($invoices->pluck('status')->all())->toBe(['draft', 'draft', 'sent'])
        ->and($invoices->first()?->emailed_at)->toBeNull()
        ->and($invoices->last()?->emailed_at)->not->toBeNull();

    Mail::assertQueuedCount(1);
});

it('keeps the generated invoice when auto-send fails on a client without email', function (): void {
    Mail::fake();

    autoSendTemplate(clientAttributes: ['email' => null]);

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    $invoice = Invoice::withoutGlobalScope('user')->firstOrFail();

    expect($invoice->status)->toBe('draft')
        ->and($invoice->emailed_at)->toBeNull();

    Mail::assertNothingQueued();
});
