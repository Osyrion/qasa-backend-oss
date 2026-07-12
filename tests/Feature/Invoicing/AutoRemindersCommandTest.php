<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Events\InvoiceOverdue;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Presentation\Mail\InvoiceReminderMail;
use App\Modules\Invoicing\Presentation\Mail\RemindersExhaustedMail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

it('does not send anything when auto_remind_enabled is off (default)', function (): void {
    Mail::fake();

    $user = createUser(['auto_remind_enabled' => false]);
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->overdue()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();

    Mail::assertNothingQueued();

    expect($invoice->refresh()->reminder_count)->toBe(0)
        ->and($invoice->overdue_notified_at)->not->toBeNull();
});

it('sends an automatic reminder to the client and increments reminder_count', function (): void {
    Mail::fake();

    $user = createUser([
        'auto_remind_enabled' => true,
        'auto_remind_max' => 3,
        'auto_remind_interval_days' => 7,
    ]);
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => 'klient@example.com']);
    $invoice = Invoice::factory()->overdue()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();

    Mail::assertQueued(
        InvoiceReminderMail::class,
        fn (InvoiceReminderMail $mail): bool => $mail->hasTo('klient@example.com'),
    );

    expect($invoice->refresh())
        ->reminder_count->toBe(1)
        ->status->toBe('reminded')
        ->last_reminded_at->not->toBeNull();
});

it('skips a reminder before auto_remind_interval_days has elapsed', function (): void {
    Mail::fake();

    $user = createUser([
        'auto_remind_enabled' => true,
        'auto_remind_max' => 3,
        'auto_remind_interval_days' => 7,
    ]);
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->overdue()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();
    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();

    Mail::assertQueued(InvoiceReminderMail::class, 1);
    expect($invoice->refresh()->reminder_count)->toBe(1);
});

it('sends the reminders-exhausted mail to the owner exactly once', function (): void {
    Mail::fake();

    $user = createUser([
        'auto_remind_enabled' => true,
        'auto_remind_max' => 1,
        'auto_remind_interval_days' => 7,
    ]);
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->overdue()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => 'reminded',
        'reminder_count' => 1,
        'last_reminded_at' => now()->subDays(10),
    ]);

    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();
    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();

    Mail::assertQueued(
        RemindersExhaustedMail::class,
        fn (RemindersExhaustedMail $mail): bool => $mail->hasTo($user->email),
    );
    Mail::assertQueued(RemindersExhaustedMail::class, 1);

    expect($invoice->refresh()->reminders_exhausted_notified_at)->not->toBeNull();
});

it('dispatches invoice.overdue only once per invoice', function (): void {
    Event::fake([InvoiceOverdue::class]);

    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->overdue()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();
    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();

    Event::assertDispatchedTimes(InvoiceOverdue::class, 1);
    expect($invoice->refresh()->overdue_notified_at)->not->toBeNull();
});

it('does not fail the run when a client has no e-mail', function (): void {
    Mail::fake();

    $user = createUser(['auto_remind_enabled' => true]);
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => null]);
    $invoice = Invoice::factory()->overdue()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();

    Mail::assertNotQueued(InvoiceReminderMail::class);
    expect($invoice->refresh()->reminder_count)->toBe(0);
});

it('isolates tenants — only the opted-in user receives reminders', function (): void {
    Mail::fake();

    $optedIn = createUser(['auto_remind_enabled' => true]);
    $optedInClient = Client::factory()->create(['user_id' => $optedIn->id, 'email' => 'in@example.com']);
    $optedInInvoice = Invoice::factory()->overdue()->create([
        'user_id' => $optedIn->id,
        'client_id' => $optedInClient->id,
    ]);

    $optedOut = createUser(['auto_remind_enabled' => false]);
    $optedOutClient = Client::factory()->create(['user_id' => $optedOut->id, 'email' => 'out@example.com']);
    $optedOutInvoice = Invoice::factory()->overdue()->create([
        'user_id' => $optedOut->id,
        'client_id' => $optedOutClient->id,
    ]);

    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();

    Mail::assertQueued(
        InvoiceReminderMail::class,
        fn (InvoiceReminderMail $mail): bool => $mail->hasTo('in@example.com'),
    );
    Mail::assertQueued(InvoiceReminderMail::class, 1);

    expect($optedInInvoice->refresh()->reminder_count)->toBe(1)
        ->and($optedOutInvoice->refresh()->reminder_count)->toBe(0);
});
