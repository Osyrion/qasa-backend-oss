<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Presentation\Mail\OverdueInvoicesDigestMail;
use Illuminate\Support\Facades\Mail;

it('sends one digest listing every invoice newly detected overdue in the run', function (): void {
    Mail::fake();

    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $first = Invoice::factory()->overdue()->create(['user_id' => $user->id, 'client_id' => $client->id]);
    $second = Invoice::factory()->overdue()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();

    Mail::assertQueued(OverdueInvoicesDigestMail::class, function (OverdueInvoicesDigestMail $mail) use ($user, $first, $second): bool {
        return $mail->hasTo($user->email)
            && $mail->invoices->count() === 2
            && $mail->invoices->pluck('id')->contains($first->id)
            && $mail->invoices->pluck('id')->contains($second->id);
    });
    Mail::assertQueued(OverdueInvoicesDigestMail::class, 1);
});

it('does not resend the digest for invoices already reported in a previous run', function (): void {
    Mail::fake();

    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    Invoice::factory()->overdue()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();
    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();

    Mail::assertQueued(OverdueInvoicesDigestMail::class, 1);
});

it('still marks invoices overdue and fires the event when the digest is disabled, but sends no mail', function (): void {
    Mail::fake();

    $user = createUser(['overdue_digest_enabled' => false]);
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->overdue()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();

    Mail::assertNotQueued(OverdueInvoicesDigestMail::class);
    expect($invoice->refresh()->overdue_notified_at)->not->toBeNull();
});

it('sends the digest independently of auto_remind_enabled', function (): void {
    Mail::fake();

    $user = createUser(['auto_remind_enabled' => false, 'overdue_digest_enabled' => true]);
    $client = Client::factory()->create(['user_id' => $user->id]);
    Invoice::factory()->overdue()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $this->artisan('qasa:invoices:send-auto-reminders')->assertSuccessful();

    Mail::assertQueued(OverdueInvoicesDigestMail::class, 1);
});

it('groups a --date backfill covering several invoices into a single digest', function (): void {
    Mail::fake();

    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    // Two invoices overdue as of a backfilled "today" — simulates a cron
    // gap where several invoices cross into overdue between runs.
    Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => 'sent',
        'issued_at' => now()->subMonths(2),
        'due_at' => now()->subMonth(),
    ]);
    Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => 'sent',
        'issued_at' => now()->subMonths(2),
        'due_at' => now()->subMonth()->addDay(),
    ]);

    $this->artisan('qasa:invoices:send-auto-reminders', ['--date' => now()->toDateString()])->assertSuccessful();

    Mail::assertQueued(
        OverdueInvoicesDigestMail::class,
        fn (OverdueInvoicesDigestMail $mail): bool => $mail->invoices->count() === 2,
    );
    Mail::assertQueued(OverdueInvoicesDigestMail::class, 1);
});
