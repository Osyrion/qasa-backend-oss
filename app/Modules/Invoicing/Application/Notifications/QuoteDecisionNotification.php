<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Notifications;

use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Shared\Application\DTOs\NotificationPayload;
use App\Modules\Shared\Application\Notifications\InAppNotification;
use App\Modules\Shared\Enums\NotificationCategory;
use App\Modules\Shared\Enums\NotificationSeverity;
use Illuminate\Queue\SerializesModels;

/**
 * The client acted on a quote — addressed to the account owner, not to the
 * client, which is why it belongs in the notification centre at all.
 */
class QuoteDecisionNotification extends InAppNotification
{
    use SerializesModels;

    public function __construct(
        public readonly Quote $quote,
        public readonly QuoteStatus $decision,
    ) {}

    public function payload(object $notifiable): NotificationPayload
    {
        $accepted = $this->decision === QuoteStatus::Accepted;

        return new NotificationPayload(
            category: NotificationCategory::Quote,
            severity: $accepted ? NotificationSeverity::Success : NotificationSeverity::Warning,
            title: __($accepted ? 'notifications.quote_accepted_title' : 'notifications.quote_rejected_title'),
            body: __($accepted ? 'notifications.quote_accepted_body' : 'notifications.quote_rejected_body', [
                'number' => (string) $this->quote->quote_number,
            ]),
            actionUrl: '/quotes/'.$this->quote->id,
            subjectType: $this->quote->getMorphClass(),
            subjectId: $this->quote->id,
        );
    }
}
