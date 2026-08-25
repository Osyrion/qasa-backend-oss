<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Listeners;

use App\Modules\Invoicing\Application\Mail\QuoteDecisionMail;
use App\Modules\Invoicing\Application\Notifications\QuoteDecisionNotification;
use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Events\QuoteAccepted;
use App\Modules\Invoicing\Domain\Events\QuoteRejected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class SendQuoteDecisionNotification implements ShouldQueue
{
    public function handle(QuoteAccepted|QuoteRejected $event): void
    {
        $quote = $event->quote;
        $quote->loadMissing('user');
        $owner = $quote->user;

        if ($owner === null || $owner->supplierProfile()->email === '') {
            return;
        }

        $decision = $event instanceof QuoteAccepted ? QuoteStatus::Accepted : QuoteStatus::Rejected;
        $locale = $owner->preferredLocale() ?? (string) config('app.locale');

        Mail::to($owner->supplierProfile()->email)->queue(
            (new QuoteDecisionMail($quote, $decision))->locale($locale)
        );

        // Notification::send() rather than $owner->notify(): notify() comes
        // from the Notifiable trait, which no contract publishes, and the
        // account here is typed as contracts rather than as the model.
        Notification::send(
            $owner,
            (new QuoteDecisionNotification($quote, $decision))->locale($locale)
        );
    }
}
