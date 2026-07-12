<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Mail;

use App\Modules\Invoicing\Application\Services\InvoicePdfService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class InvoiceEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly ?string $customMessage = null,
        public readonly ?string $publicUrl = null,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('invoices::emails.subject', ['number' => $this->invoice->invoice_number]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'invoices::emails.invoice',
            with: [
                'invoice' => $this->invoice,
                'customMessage' => $this->customMessage,
                'supplierName' => $this->invoice->supplier_snapshot['name'] ?? null,
                'publicUrl' => $this->publicUrl,
            ],
        );
    }

    /**
     * The PDF is rendered lazily on the queue worker, so the queued
     * payload carries only the serialized model reference.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $pdfService = app(InvoicePdfService::class);

        return [
            Attachment::fromData(
                fn (): string => $pdfService->generate($this->invoice),
                $pdfService->filename($this->invoice),
            )->withMime('application/pdf'),
        ];
    }

    /**
     * Called by SendQueuedMailable once the job permanently fails (retries
     * exhausted). emailed_at only means "queued", so the failure has to be
     * written back for the invoice to stop claiming it was emailed.
     */
    public function failed(Throwable $exception): void
    {
        $this->invoice->forceFill(['email_failed_at' => now()])->saveQuietly();

        Log::error('Invoice email permanently failed', [
            'invoice_id' => $this->invoice->id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
