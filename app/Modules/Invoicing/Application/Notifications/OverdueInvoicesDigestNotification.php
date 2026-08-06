<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Notifications;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Application\DTOs\NotificationPayload;
use App\Modules\Shared\Application\Notifications\InAppNotification;
use App\Modules\Shared\Enums\NotificationCategory;
use App\Modules\Shared\Enums\NotificationSeverity;
use App\Modules\Shared\Support\Decimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\SerializesModels;

/**
 * The owner's "these just went past due" digest, in the notification centre.
 *
 * Deliberately database-only, alongside OverdueInvoicesDigestMail rather
 * than instead of it. Returning the Mailable from toMail() looked tidier,
 * but MailChannel then sends it through a path Mail::fake() cannot see as a
 * Mailable at all — Mail::assertQueued(OverdueInvoicesDigestMail::class)
 * stops matching, and the e-mail becomes the one part of this that is no
 * longer directly assertable. Two dispatches keep both channels
 * independently testable, and mean a mail failure never costs the user the
 * in-app copy.
 */
class OverdueInvoicesDigestNotification extends InAppNotification
{
    use SerializesModels;

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    public function __construct(
        public readonly Collection $invoices,
    ) {}

    public function payload(object $notifiable): NotificationPayload
    {
        // Decimal, not a float sum: these are money columns, and the rule
        // in CLAUDE.md is that everything touching them goes through the
        // same exact arithmetic even when the result is only displayed.
        $total = Decimal::money(Decimal::sum($this->invoices->map(fn (Invoice $invoice): string => (string) $invoice->total)));
        $currency = $this->invoices->first()?->currency->value ?? '';

        return new NotificationPayload(
            category: NotificationCategory::Invoice,
            severity: NotificationSeverity::Warning,
            title: __('notifications.overdue_digest_title'),
            body: __('notifications.overdue_digest_body', [
                'count' => (string) $this->invoices->count(),
                'amount' => trim($total.' '.$currency),
            ]),
            // A digest has many subjects, so it points at the filtered list
            // rather than at any one of them.
            actionUrl: '/invoices?status=overdue',
        );
    }
}
