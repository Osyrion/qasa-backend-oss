<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Mail;

use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RemindersExhaustedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly int $reminderCount,
        public readonly int $maxReminders,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('invoices::emails.reminders_exhausted_subject', [
                'number' => $this->invoice->invoice_number,
                'count' => $this->reminderCount,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'invoices::emails.reminders-exhausted',
            with: [
                'invoice' => $this->invoice,
                'reminderCount' => $this->reminderCount,
                'maxReminders' => $this->maxReminders,
            ],
        );
    }
}
