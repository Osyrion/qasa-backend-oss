<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\DTOs\SendInvoiceEmailData;
use App\Modules\Invoicing\Application\Mail\QuoteEmail;
use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Mail;
use Throwable;

readonly class SendQuoteEmailAction
{
    public function __construct(
        private UpdateQuoteStatusAction $updateStatusAction,
        private CreateQuotePublicLinkAction $createPublicLinkAction,
    ) {}

    /**
     * Email the quote to the client, freezing snapshots and moving
     * draft -> sent first when still a draft.
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Quote $quote, ?SendInvoiceEmailData $data = null): Quote
    {
        // Resolve the recipient before any state change so a draft without
        // a deliverable address stays untouched.
        $recipient = $this->resolveRecipient($quote, $data);

        if ($recipient === null) {
            throw DomainException::because(__('invoicing.quote_client_email_missing'));
        }

        if ($quote->isDraft()) {
            $quote = $this->updateStatusAction->execute($quote, QuoteStatus::Sent);
        }

        $publicUrl = $this->createPublicLinkAction->execute($quote)->publicUrl();

        $locale = $quote->client->locale ?? $quote->user->locale ?? (string) config('app.locale');

        Mail::to($recipient)->queue(
            (new QuoteEmail($quote, $data?->message, $publicUrl))->locale($locale)
        );

        $quote->update([
            'emailed_at' => now(),
            'emailed_to' => $recipient,
        ]);

        return $quote->refresh();
    }

    private function resolveRecipient(Quote $quote, ?SendInvoiceEmailData $data): ?string
    {
        $email = $data->to
            ?? $quote->client_snapshot['email']
            ?? $quote->client?->email;

        return ($email === null || $email === '') ? null : (string) $email;
    }
}
