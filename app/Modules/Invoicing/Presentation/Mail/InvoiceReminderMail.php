<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Mail;

use App\Modules\Invoicing\Application\Services\InvoicePdfService;
use App\Modules\Invoicing\Application\Services\PaymentQrService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceReminderMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('invoices::emails.reminder_subject', ['number' => $this->invoice->invoice_number]),
        );
    }

    /**
     * Bank details and the QR PNG are resolved here, on the queue worker at
     * send time, so the queued payload carries only the serialized model
     * reference — same principle as the lazy PDF attachment below.
     */
    public function content(): Content
    {
        $qrService = app(PaymentQrService::class);
        $bank = $qrService->bankDetails($this->invoice);

        return new Content(
            view: 'invoices::emails.reminder',
            with: [
                'invoice' => $this->invoice,
                'supplierName' => $this->invoice->supplier_snapshot['name'] ?? null,
                'iban' => isset($bank['iban']) && $bank['iban'] !== '' ? (string) $bank['iban'] : null,
                'bic' => isset($bank['bic']) && $bank['bic'] !== '' ? (string) $bank['bic'] : null,
                'qrPng' => $qrService->png($this->invoice, $this->invoice->balance()),
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
}
