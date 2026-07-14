<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Presentation\Mail\InvoiceReminderMail;

/**
 * @param  array<string, mixed>  $attributes
 */
function reminderInvoice(array $attributes = []): Invoice
{
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => 'klient@example.com']);

    return Invoice::factory()->create(array_merge([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => 'sent',
        'currency' => 'EUR',
        'total' => 1000,
        'variable_symbol' => '2026001',
        'bank_account_snapshot' => [
            'label' => 'Main',
            'bank_name' => 'Slovenská sporiteľňa',
            'account_number' => null,
            'iban' => 'SK3112000000198742637541',
            'bic' => 'GIBASKBX',
            'currency' => 'EUR',
        ],
    ], $attributes));
}

it('renders bank payment details in the reminder email body', function (): void {
    $invoice = reminderInvoice();

    $mail = new InvoiceReminderMail($invoice);

    $mail->assertSeeInHtml('SK3112000000198742637541')
        ->assertSeeInHtml('GIBASKBX')
        ->assertSeeInHtml('2026001')
        ->assertSeeInHtml(__('invoices::emails.payment_details'));
});

it('omits the bank detail rows when the invoice has no bank account', function (): void {
    $invoice = reminderInvoice(['bank_account_snapshot' => null, 'bank_account_id' => null]);

    $mail = new InvoiceReminderMail($invoice);

    $mail->assertDontSeeInHtml(__('invoices::emails.iban').':');

    expect($mail->content()->with['qrPng'])->toBeNull();
});

it('produces a PNG payment QR encoding the outstanding balance', function (): void {
    if (! extension_loaded('imagick') && ! extension_loaded('gd')) {
        $this->markTestSkipped('Neither imagick nor gd is available for PNG QR rendering.');
    }

    $invoice = reminderInvoice();

    $qrPng = (new InvoiceReminderMail($invoice))->content()->with['qrPng'];

    expect($qrPng)->not->toBeNull()
        ->and(substr((string) $qrPng, 0, 4))->toBe("\x89PNG");
});
