<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientUsageGuardInterface;
use App\Modules\Invoicing\Application\Mail\InvoiceReminderMail;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Events\InvoiceReminded;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\VerifiedSenderGuard;
use Illuminate\Support\Facades\Mail;
use Throwable;

readonly class RemindInvoiceAction
{
    public function __construct(
        private CreateInvoicePublicLinkAction $createPublicLinkAction,
        private ClientUsageGuardInterface $usageGuard,
    ) {}

    /**
     * Send a payment reminder for a sent (and still unpaid) invoice.
     * Repeat reminders are throttled by the configured cooldown.
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice): Invoice
    {
        VerifiedSenderGuard::ensureCanSend($invoice->user);

        if ($invoice->client !== null) {
            $this->usageGuard->ensureUsable($invoice->client);
        }

        if (! in_array($invoice->statusEnum(), [InvoiceStatus::Sent, InvoiceStatus::Reminded], true)) {
            throw DomainException::because(
                __('invoicing.reminder_only_for_sent', ['status' => $invoice->statusEnum()->label()])
            );
        }

        $cooldownDays = (int) config('invoicing.reminder_cooldown_days', 3);

        if ($invoice->last_reminded_at !== null
            && $invoice->last_reminded_at->addDays($cooldownDays)->isFuture()
        ) {
            $nextAllowed = $invoice->last_reminded_at->addDays($cooldownDays);

            throw DomainException::because(
                __('invoicing.reminder_cooldown', ['next_allowed' => $nextAllowed->format('d.m.Y H:i')])
            );
        }

        $invoice->loadMissing(['client', 'items', 'user']);

        $email = $invoice->client_snapshot['email'] ?? $invoice->client?->email;

        if ($email === null || $email === '') {
            throw DomainException::because(__('invoicing.client_email_missing_for_reminder'));
        }

        $locale = $invoice->client->locale ?? $invoice->user?->preferredLocale() ?? (string) config('app.locale');

        $publicUrl = null;

        if (config('invoicing.public_link_in_emails')) {
            $publicUrl = $this->createPublicLinkAction->execute($invoice)->publicUrl();
        }

        Mail::to($email)->queue((new InvoiceReminderMail($invoice, $publicUrl))->locale($locale));

        $invoice->update([
            'status' => InvoiceStatus::Reminded->value,
            'last_reminded_at' => now(),
            'reminder_count' => $invoice->reminder_count + 1,
        ]);

        $updated = $invoice->refresh();

        event(new InvoiceReminded($updated));

        return $updated;
    }
}
