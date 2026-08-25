<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\ReceivablesAnalytics;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\ValueObjects\OutstandingDocument;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

final class EloquentReceivablesAnalytics implements ReceivablesAnalytics
{
    public function openReceivables(string $ownerId, ?string $clientId = null): array
    {
        // The balance comes from a join rather than a correlated subquery —
        // the subquery re-runs per invoice — and is rounded per document,
        // because rounding only the sums drifts by a cent per partial payment.
        //
        // A proforma is never a receivable, with or without a settled invoice:
        // filtering to type=invoice excludes it entirely.
        $query = Invoice::withoutGlobalScope('user')
            ->leftJoin('invoice_payments', 'invoice_payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.user_id', $ownerId)
            ->where('invoices.type', InvoiceType::Invoice->value)
            ->whereIn('invoices.status', array_map(
                static fn (InvoiceStatus $status): string => $status->value,
                InvoiceStatus::openStatuses(),
            ))
            ->groupBy('invoices.id', 'invoices.client_id', 'invoices.currency', 'invoices.due_at', 'invoices.total')
            ->selectRaw('invoices.client_id, invoices.currency as currency_code, invoices.due_at,
                round(invoices.total - coalesce(sum(invoice_payments.amount), 0), 2) as balance');

        if ($clientId !== null) {
            $query->where('invoices.client_id', $clientId);
        }

        /** @var list<OutstandingDocument> */
        return $query->toBase()->get()
            ->map(fn (object $row): OutstandingDocument => new OutstandingDocument(
                clientId: $row->client_id === null ? null : (string) $row->client_id,
                currency: Currency::from((string) $row->currency_code),
                dueAt: Carbon::parse((string) $row->due_at),
                outstanding: (float) $row->balance,
            ))
            ->values()
            ->all();
    }

    public function openPayables(string $ownerId, ?string $clientId = null): array
    {
        $query = SupplierInvoice::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->whereIn('status', ['received', 'booked']);

        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        /** @var list<OutstandingDocument> */
        return $query->get(['client_id', 'currency', 'due_at', 'total'])
            ->map(fn (SupplierInvoice $invoice): OutstandingDocument => new OutstandingDocument(
                clientId: $invoice->client_id,
                currency: $invoice->currency,
                dueAt: $invoice->due_at,
                outstanding: (float) $invoice->total,
            ))
            ->values()
            ->all();
    }
}
