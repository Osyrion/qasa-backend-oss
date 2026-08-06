<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Console;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Notifications\OverdueInvoicesDigestNotification;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Events\InvoiceOverdue;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Presentation\Mail\OverdueInvoicesDigestMail;
use App\Modules\Shared\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Independent of qasa:invoices:auto-remind (which emails the client, opt-in
 * per account): this notifies the account owner about their own invoices
 * newly crossing into overdue, once per owner per run — a cron gap or a
 * returning-from-holiday backlog must not flood the inbox with one email
 * per invoice. overdue_notified_at is the idempotency marker, so re-running
 * the same day never re-detects an invoice already flagged.
 */
class SendOverdueDigestCommand extends Command
{
    protected $signature = 'qasa:invoices:overdue-digest
        {--date= : Treat this date as today (testing/backfill)}';

    protected $description = "Detect invoices newly past due and email the account owner a digest, per the owner's overdue_digest_enabled setting";

    public function handle(): int
    {
        /** @var string|null $dateOption */
        $dateOption = $this->option('date');
        $today = CarbonImmutable::parse($dateOption ?? 'today')->startOfDay();

        $detected = 0;
        $digestsSent = 0;
        $failures = 0;

        // Account by account, bound to each in turn — reads, writes and the
        // queued digest alike. A console command has nobody to bind it, and
        // invoices sit behind a row-level policy that only ever exposes the
        // bound account, so one query across them all would silently return
        // nothing. users is tenant-scoped too now (phase 7), so the
        // candidate list itself has to come from forEachAccount() rather
        // than a direct scan — it walks the account ids via a definer
        // function and binds each in turn, which is what makes User::find()
        // below see the row at all. Team members fall out on their own:
        // their id never owns an invoice, so the query below is empty for
        // them and forEachAccount() already dedupes each account to its
        // owner regardless.
        TenantContext::forEachAccount(function (string $accountId) use ($today, &$detected, &$digestsSent, &$failures): void {
            $user = User::find($accountId);

            if ($user === null) {
                return;
            }

            $invoices = Invoice::withoutGlobalScope('user')
                ->where('user_id', $user->id)
                ->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Reminded->value])
                ->where('due_at', '<', $today)
                ->whereNull('overdue_notified_at')
                ->get();

            if ($invoices->isEmpty()) {
                return;
            }

            /** @var Collection<int, Invoice> $newlyOverdue */
            $newlyOverdue = new Collection;

            foreach ($invoices as $invoice) {
                $invoice->update(['overdue_notified_at' => now()]);
                event(new InvoiceOverdue($invoice));
                $detected++;
                $newlyOverdue->push($invoice);
            }

            if (! $user->overdue_digest_enabled) {
                return;
            }

            try {
                Mail::to($user->email)->queue(
                    (new OverdueInvoicesDigestMail($newlyOverdue))->locale($user->locale)
                );

                // Same message, second channel. Sent separately rather than
                // as another via() of one notification — see the class
                // docblock for why wrapping the Mailable costs more than it
                // saves.
                $user->notify(
                    (new OverdueInvoicesDigestNotification($newlyOverdue))->locale($user->locale)
                );
                $digestsSent++;
                $this->line("Owner {$user->email}: overdue digest sent for {$newlyOverdue->count()} invoice(s).");
            } catch (Throwable $e) {
                $failures++;
                report($e);
                Log::error('Overdue invoices digest failed', [
                    'user_id' => $user->id,
                    'exception' => $e->getMessage(),
                ]);
                $this->error("Owner {$user->email}: overdue digest failed: {$e->getMessage()}");
            }
        });

        $this->info("Done: {$detected} invoices marked overdue, {$digestsSent} digests sent, {$failures} failures.");

        // The overdue_notified_at marker is already persisted per invoice
        // even when the mail send fails, so a failure never causes the same
        // invoice to be re-flagged and re-detected on the next run.
        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
