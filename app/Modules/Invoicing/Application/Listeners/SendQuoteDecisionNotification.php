<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Listeners;

use App\Modules\Invoicing\Application\Mail\QuoteDecisionMail;
use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Events\QuoteAccepted;
use App\Modules\Invoicing\Domain\Events\QuoteRejected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendQuoteDecisionNotification implements ShouldQueue
{
    public function handle(QuoteAccepted|QuoteRejected $event): void
    {
        $quote = $event->quote;
        $quote->loadMissing('user');
        $owner = $quote->user;

        if ($owner === null || $owner->email === '') {
            return;
        }

        $decision = $event instanceof QuoteAccepted ? QuoteStatus::Accepted : QuoteStatus::Rejected;

        Mail::to($owner->email)->queue(
            (new QuoteDecisionMail($quote, $decision))->locale($owner->locale)
        );
    }
}
