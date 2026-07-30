<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Mail;

use App\Modules\Invoicing\Application\Services\QuotePdfService;
use App\Modules\Invoicing\Domain\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Quote $quote,
        public readonly ?string $customMessage = null,
        public readonly ?string $publicUrl = null,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('invoices::emails.quote_subject', ['number' => $this->quote->quote_number]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'invoices::emails.quote',
            with: [
                'quote' => $this->quote,
                'customMessage' => $this->customMessage,
                'supplierName' => $this->quote->supplier_snapshot['name'] ?? null,
                'publicUrl' => $this->publicUrl,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $pdfService = app(QuotePdfService::class);

        return [
            Attachment::fromData(
                fn (): string => $pdfService->generate($this->quote),
                $pdfService->filename($this->quote),
            )->withMime('application/pdf'),
        ];
    }
}
