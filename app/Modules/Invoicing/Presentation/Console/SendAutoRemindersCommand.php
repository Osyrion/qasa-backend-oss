<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Console;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\RemindInvoiceAction;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Events\InvoiceOverdue;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Presentation\Mail\RemindersExhaustedMail;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAutoRemindersCommand extends Command
{
    protected $signature = 'qasa:invoices:send-auto-reminders
        {--date= : Treat this date as today (testing/backfill)}';

    protected $description = 'Detect newly overdue invoices and, for opted-in tenants, send automatic payment reminders';

    public function handle(RemindInvoiceAction $remindAction): int
    {
        /** @var string|null $dateOption */
        $dateOption = $this->option('date');
        $today = CarbonImmutable::parse($dateOption ?? 'today')->startOfDay();

        $overdueDetected = 0;
        $sent = 0;
        $exhausted = 0;
        $failures = 0;

        // Console has no auth context, so the invoice HasUserScope global
        // scope is a no-op anyway; withoutGlobalScope is belt-and-braces.
        // The per-tenant overdue_reminder_days threshold means this can't
        // be a single cross-tenant query, so we loop per user (same idiom
        // as DashboardService::overdueReminders()).
        foreach (User::query()->cursor() as $user) {
            $cutoff = $today->subDays($user->overdue_reminder_days);

            $invoices = Invoice::withoutGlobalScope('user')
                ->where('user_id', $user->id)
                ->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Reminded->value])
                ->where('due_at', '<', $cutoff)
                ->get();

            foreach ($invoices as $invoice) {
                // Overdue detection runs for every tenant regardless of
                // auto_remind_enabled — it's just an idempotent marker plus
                // a webhook seam (Fáza 9), not a reminder send.
                if ($invoice->overdue_notified_at === null) {
                    $invoice->update(['overdue_notified_at' => now()]);
                    event(new InvoiceOverdue($invoice));
                    $overdueDetected++;
                }

                if (! $user->auto_remind_enabled) {
                    continue;
                }

                if ($invoice->reminder_count < $user->auto_remind_max) {
                    $dueForReminder = $invoice->last_reminded_at === null
                        || $invoice->last_reminded_at->addDays($user->auto_remind_interval_days)->isPast();

                    if (! $dueForReminder) {
                        continue;
                    }

                    try {
                        $remindAction->execute($invoice);
                        $sent++;
                        $this->line("Invoice {$invoice->invoice_number}: auto-reminder sent.");
                    } catch (Throwable $e) {
                        $failures++;
                        report($e);
                        Log::error('Automatic invoice reminder failed', [
                            'invoice_id' => $invoice->id,
                            'exception' => $e->getMessage(),
                        ]);
                        $this->error("Invoice {$invoice->invoice_number}: auto-reminder failed: {$e->getMessage()}");
                    }
                } elseif ($invoice->reminders_exhausted_notified_at === null) {
                    Mail::to($user->email)->queue(
                        (new RemindersExhaustedMail($invoice, $invoice->reminder_count, $user->auto_remind_max))
                            ->locale($user->locale)
                    );
                    $invoice->update(['reminders_exhausted_notified_at' => now()]);
                    $exhausted++;
                    $this->line("Invoice {$invoice->invoice_number}: reminders exhausted, owner notified.");
                }
            }
        }

        $this->info("Done: {$overdueDetected} invoices marked overdue, {$sent} reminders sent, {$exhausted} owners notified of exhausted reminders, {$failures} failures.");

        // A failed send leaves the invoice exactly as it was (no partial
        // state), so per-invoice failures don't need to fail the whole run.
        return self::SUCCESS;
    }
}
