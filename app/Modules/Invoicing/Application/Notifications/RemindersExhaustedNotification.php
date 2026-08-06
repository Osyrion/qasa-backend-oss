<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Notifications;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Application\DTOs\NotificationPayload;
use App\Modules\Shared\Application\Notifications\InAppNotification;
use App\Modules\Shared\Enums\NotificationCategory;
use App\Modules\Shared\Enums\NotificationSeverity;
use Illuminate\Queue\SerializesModels;

/**
 * The automation has given up on an invoice. Precisely the message that
 * must not sit unnoticed in an inbox — it is the point at which chasing the
 * money becomes a human's job again.
 */
class RemindersExhaustedNotification extends InAppNotification
{
    use SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly int $reminderCount,
        public readonly int $maxReminders,
    ) {}

    public function payload(object $notifiable): NotificationPayload
    {
        return new NotificationPayload(
            category: NotificationCategory::Invoice,
            severity: NotificationSeverity::Error,
            title: __('notifications.reminders_exhausted_title'),
            body: __('notifications.reminders_exhausted_body', [
                'number' => (string) $this->invoice->invoice_number,
                'count' => (string) $this->reminderCount,
            ]),
            actionUrl: '/invoices/'.$this->invoice->id,
            subjectType: $this->invoice->getMorphClass(),
            subjectId: $this->invoice->id,
        );
    }
}
