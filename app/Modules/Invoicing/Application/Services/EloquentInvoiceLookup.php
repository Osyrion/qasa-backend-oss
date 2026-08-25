<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\InvoiceLookup;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Invoicing\Domain\ValueObjects\InvoiceSummary;
use App\Modules\Invoicing\Domain\ValueObjects\PaymentMatchCandidate;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only place outside Invoicing's own code that turns an invoice row into
 * something another module may hold.
 *
 * The single-document reads leave the account scope on deliberately: a
 * summary for a document the caller's account cannot see comes back null,
 * which is the same answer the consumer would have got from a scoped query of
 * its own.
 *
 * The account-wide reads take the owner as an argument instead, because their
 * callers are not always inside a request that has one — the bank sync runs
 * as a console command, where the scope is a no-op and the account comes from
 * whichever tenant the loop has bound.
 */
final readonly class EloquentInvoiceLookup implements InvoiceLookup
{
    public function summary(string $invoiceId): ?InvoiceSummary
    {
        $invoice = Invoice::query()->with('client')->find($invoiceId);

        if (! $invoice instanceof Invoice) {
            return null;
        }

        return new InvoiceSummary(
            id: $invoice->id,
            number: $invoice->invoice_number,
            // The snapshot first: an issued document prints the name it was
            // issued with, even after the client is renamed.
            clientName: (string) ($invoice->client_snapshot['name'] ?? $invoice->client->display_name ?? ''),
            currency: $invoice->currency,
            total: (float) $invoice->total,
            dueAt: $invoice->due_at,
            reminderCount: $invoice->reminder_count,
        );
    }

    public function numberFor(string $invoiceId): ?string
    {
        $invoice = Invoice::withTrashed()->find($invoiceId);

        return $invoice instanceof Invoice ? $invoice->invoice_number : null;
    }

    public function clientIdFor(string $invoiceId): ?string
    {
        /** @var string|null $clientId */
        $clientId = Invoice::query()->whereKey($invoiceId)->value('client_id');

        return $clientId;
    }

    public function openForMatching(string $ownerId): array
    {
        /** @var list<PaymentMatchCandidate> $candidates */
        $candidates = Invoice::query()
            ->forUser($ownerId)
            ->whereIn('status', array_map(fn (InvoiceStatus $s): string => $s->value, InvoiceStatus::openStatuses()))
            ->whereIn('type', [InvoiceType::Invoice->value, InvoiceType::Proforma->value])
            // Both of these are load-bearing rather than tidy: without the
            // sum, balance() below falls back to payments()->sum() and turns
            // this into one query per open invoice; without the relation, so
            // does every client field.
            ->withSum('payments', 'amount')
            ->with('client')
            ->get()
            ->map(fn (Invoice $invoice): PaymentMatchCandidate => new PaymentMatchCandidate(
                invoiceId: $invoice->id,
                number: $invoice->invoice_number,
                variableSymbol: $invoice->variable_symbol,
                currency: $invoice->currency,
                balance: $invoice->balance(),
                dueAt: $invoice->due_at,
                clientBankIban: $invoice->client?->bank_iban,
                // The client's name as it stands today, not the snapshot the
                // document was issued with: this is matched against who the
                // bank says paid, and the bank knows them by their current
                // name.
                clientName: $invoice->client?->display_name,
            ))
            ->values()
            ->all();

        return $candidates;
    }

    public function importedBankReferences(string $ownerId): array
    {
        /** @var list<string> $references */
        $references = $this->bankReferencesOf($ownerId)->pluck('bank_reference')->all();

        return $references;
    }

    public function hasImportedBankReference(string $ownerId, string $bankReference): bool
    {
        return $this->bankReferencesOf($ownerId)->where('bank_reference', $bankReference)->exists();
    }

    public function hasStripePaymentIntent(string $ownerId, string $paymentIntentId): bool
    {
        return InvoicePayment::query()
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->whereRelation('invoice', 'invoices.user_id', $ownerId)
            ->exists();
    }

    /**
     * @return Builder<InvoicePayment>
     */
    private function bankReferencesOf(string $ownerId): Builder
    {
        return InvoicePayment::query()
            ->whereNotNull('bank_reference')
            ->whereRelation('invoice', 'invoices.user_id', $ownerId);
    }
}
