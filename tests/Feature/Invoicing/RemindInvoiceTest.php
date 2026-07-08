<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Presentation\Mail\InvoiceReminderMail;
use Illuminate\Support\Facades\Mail;

it('sends a reminder for an overdue invoice and enforces the cooldown', function (): void {
    Mail::fake();

    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => 'klient@example.com']);
    $invoice = Invoice::factory()->overdue()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $first = $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/remind");
    $first->assertOk()
        ->assertJsonPath('status', 'reminded')
        ->assertJsonPath('reminder_count', 1);

    Mail::assertQueued(
        InvoiceReminderMail::class,
        fn (InvoiceReminderMail $mail): bool => $mail->hasTo('klient@example.com'),
    );

    // Immediately again — inside the cooldown window.
    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/remind")
        ->assertUnprocessable();

    // After the cooldown passes, the next reminder goes out.
    $this->travel((int) config('invoicing.reminder_cooldown_days') + 1)->days();
    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/remind")
        ->assertOk()
        ->assertJsonPath('reminder_count', 2);
});

it('refuses to remind on a draft invoice', function (): void {
    Mail::fake();

    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/remind")
        ->assertUnprocessable();

    Mail::assertNothingQueued();
});

it('refuses to remind when the client has no e-mail', function (): void {
    Mail::fake();

    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id, 'email' => null]);
    $invoice = Invoice::factory()->overdue()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/remind")
        ->assertUnprocessable();

    Mail::assertNothingQueued();
});

it('does not let a user remind on another account invoice', function (): void {
    $victim = createUser();
    $victimClient = Client::factory()->create(['user_id' => $victim->id]);
    $victimInvoice = Invoice::factory()->overdue()->create([
        'user_id' => $victim->id,
        'client_id' => $victimClient->id,
    ]);

    $attacker = createUser();

    $this->actingAs($attacker)
        ->postJson("/api/v1/invoices/{$victimInvoice->id}/remind")
        ->assertNotFound();
});
