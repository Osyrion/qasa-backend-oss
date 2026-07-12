<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Mail;

use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies the tenant that the client acted on their quote — sent to the
 * account owner, not the client.
 */
class QuoteDecisionMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Quote $quote,
        public readonly QuoteStatus $decision,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $key = $this->decision === QuoteStatus::Accepted
            ? 'invoices::emails.quote_accepted_subject'
            : 'invoices::emails.quote_rejected_subject';

        return new Envelope(
            subject: __($key, ['number' => $this->quote->quote_number]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'invoices::emails.quote-decision',
            with: [
                'quote' => $this->quote,
                'accepted' => $this->decision === QuoteStatus::Accepted,
            ],
        );
    }
}
