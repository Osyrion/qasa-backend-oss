<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\DTOs\SendInvoiceEmailData;
use App\Modules\Invoicing\Application\Mail\InvoiceEmail;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Mail;
use Throwable;

readonly class SendInvoiceEmailAction
{
    public function __construct(
        private UpdateInvoiceStatusAction $updateStatusAction,
        private CreateInvoicePublicLinkAction $createPublicLinkAction,
    ) {}

    /**
     * Email the invoice to the client, issuing it first when still a draft.
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice, ?SendInvoiceEmailData $data = null): Invoice
    {
        if ($invoice->isCancelled()) {
            throw DomainException::because(__('invoicing.cannot_email_cancelled_invoice'));
        }

        // Resolve the recipient before any state change so a draft without
        // a deliverable address stays untouched.
        $recipient = $this->resolveRecipient($invoice, $data);

        if ($recipient === null) {
            throw DomainException::because(__('invoicing.client_email_missing_for_send'));
        }

        if ($invoice->isDraft()) {
            $invoice = $this->updateStatusAction->execute($invoice, InvoiceStatus::Sent);
        }

        $publicUrl = null;

        if (config('invoicing.public_link_in_emails')) {
            $publicUrl = $this->createPublicLinkAction->execute($invoice)->publicUrl();
        }

        $locale = $invoice->client->locale ?? $invoice->user->locale ?? (string) config('app.locale');

        Mail::to($recipient)
            ->cc($data->cc ?? [])
            ->queue((new InvoiceEmail($invoice, $data?->message, $publicUrl))->locale($locale));

        // emailed_at means "handed to the mail queue" — delivery retries
        // are the queue worker's responsibility. A permanent job failure
        // sets email_failed_at via InvoiceEmail::failed().
        $invoice->update([
            'emailed_at' => now(),
            'emailed_to' => $recipient,
            'emailed_cc' => $data?->cc,
            'email_failed_at' => null,
        ]);

        return $invoice->refresh();
    }

    private function resolveRecipient(Invoice $invoice, ?SendInvoiceEmailData $data): ?string
    {
        $email = $data->to
            ?? $invoice->client_snapshot['email']
            ?? $invoice->client?->email;

        return ($email === null || $email === '') ? null : (string) $email;
    }
}
