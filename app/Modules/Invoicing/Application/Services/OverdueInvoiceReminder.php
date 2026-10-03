<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Actions\RemindInvoiceAction;
use App\Modules\Invoicing\Application\Contracts\InvoiceReminderRunner;
use App\Modules\Invoicing\Application\Mail\RemindersExhaustedMail;
use App\Modules\Invoicing\Application\Notifications\RemindersExhaustedNotification;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\ReminderResult;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\ValueObjects\ReminderOutcome;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesInvoicingPreferences;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use App\Modules\Shared\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Moved here from Automation's scheduled command, which is where it used to
 * live along with Invoicing's model, action, mailable and notification — see
 * InvoiceReminderRunner for the split.
 *
 * Assumes the connection is already bound to the account: the caller walks
 * the accounts, this only ever handles the bound one.
 */
final readonly class OverdueInvoiceReminder implements InvoiceReminderRunner
{
    public function __construct(
        private RemindInvoiceAction $remindInvoice,
    ) {}

    public function remindOverdue(
        Account&HasLocalePreference&ProvidesInvoicingPreferences&ProvidesSupplierProfile $owner,
        CarbonImmutable $today,
    ): array {
        $locale = $owner->preferredLocale() ?? (string) config('app.locale');
        $maxCount = $owner->autoRemindMaxCount();

        // Coarse filter in SQL (status + due date). The exact per-account
        // after_days/max_count checks stay in PHP below: this loop sends mail
        // and writes back per invoice, and the reminders-exhausted branch
        // still has to see rows that are no longer eligible, so a tighter
        // WHERE would change behaviour.
        // user and client eagerly: RemindInvoiceAction reads both on every
        // invoice it is handed (the sender guard, then the usage guard), and a
        // scheduled run over an account's overdue invoices was two queries per
        // invoice. The account is the same row for all of them.
        $candidates = Invoice::withoutGlobalScope('user')
            ->with(['user', 'client'])
            ->where('user_id', $owner->accountOwnerId())
            ->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Reminded->value])
            ->where('due_at', '<=', $today)
            ->get();

        $outcomes = [];

        foreach ($candidates as $invoice) {
            $outcomes[] = new ReminderOutcome(
                $this->remindOne($invoice, $owner, $today, $locale, $maxCount),
                $invoice->invoice_number,
            );
        }

        return $outcomes;
    }

    private function remindOne(
        Invoice $invoice,
        Account&HasLocalePreference&ProvidesInvoicingPreferences&ProvidesSupplierProfile $owner,
        CarbonImmutable $today,
        string $locale,
        int $maxCount,
    ): ReminderResult {
        if ($today->lessThan($invoice->due_at->addDays($owner->autoRemindAfterDays()))) {
            return ReminderResult::Skipped;
        }

        // Hard cap: an invoice is never reminded more than max_count times
        // total (manual + automatic), no matter what the per-account setting
        // is.
        if ($invoice->reminder_count >= $maxCount) {
            return $this->notifyExhausted($invoice, $owner, $locale, $maxCount);
        }

        try {
            $this->remindInvoice->execute($invoice);

            return ReminderResult::Sent;
        } catch (DomainException $e) {
            Log::info('Auto-reminder skipped', [
                'invoice_id' => $invoice->id,
                'reason' => $e->getMessage(),
            ]);

            return ReminderResult::Skipped;
        } catch (Throwable $e) {
            report($e);
            Log::error('Auto-reminder failed', [
                'invoice_id' => $invoice->id,
                'exception' => $e->getMessage(),
            ]);

            return ReminderResult::Failed;
        }
    }

    /**
     * Told once, so an unpaid invoice does not silently drop out of the flow
     * when the automation gives up on it.
     */
    private function notifyExhausted(
        Invoice $invoice,
        Account&HasLocalePreference&ProvidesSupplierProfile $owner,
        string $locale,
        int $maxCount,
    ): ReminderResult {
        if ($invoice->reminders_exhausted_notified_at !== null) {
            return ReminderResult::Skipped;
        }

        Mail::to($owner->supplierProfile()->email)->queue(
            (new RemindersExhaustedMail($invoice, $invoice->reminder_count, $maxCount))->locale($locale),
        );

        // Notification::send() rather than $owner->notify(): notify() comes
        // from the Notifiable trait, which no contract publishes.
        Notification::send(
            $owner,
            (new RemindersExhaustedNotification($invoice, $invoice->reminder_count, $maxCount))->locale($locale),
        );

        $invoice->update(['reminders_exhausted_notified_at' => now()]);

        return ReminderResult::Exhausted;
    }
}
