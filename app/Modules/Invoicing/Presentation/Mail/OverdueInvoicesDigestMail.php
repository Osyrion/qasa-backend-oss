<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Mail;

use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One e-mail per owner per run, listing every invoice that newly crossed
 * into overdue during this SendAutoRemindersCommand pass — never one e-mail
 * per invoice, so a cron gap or a returning-from-holiday backlog doesn't
 * flood the owner's inbox.
 */
class OverdueInvoicesDigestMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    public function __construct(
        public readonly Collection $invoices,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('invoices::emails.overdue_digest_subject', ['count' => $this->invoices->count()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'invoices::emails.overdue-digest',
            with: ['invoices' => $this->invoices],
        );
    }
}
