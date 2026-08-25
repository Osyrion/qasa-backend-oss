<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\InvoicingWorkQueue;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Invoicing\Domain\ValueObjects\DocumentTally;
use Illuminate\Support\Carbon;

final class EloquentInvoicingWorkQueue implements InvoicingWorkQueue
{
    public function overdueInvoices(string $ownerId): DocumentTally
    {
        // balance() is the model's own arithmetic over the payments sum, so
        // the rows are loaded and filtered here rather than summed in SQL —
        // the set is one account's overdue invoices, which is bounded by how
        // far behind its clients are.
        $invoices = Invoice::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Reminded->value])
            ->where('due_at', '<', Carbon::today()->toDateString())
            ->withSum('payments', 'amount')
            ->get()
            ->filter(fn (Invoice $invoice): bool => $invoice->balance() > 0.0);

        return new DocumentTally(
            count: $invoices->count(),
            amount: (float) $invoices->sum(fn (Invoice $invoice): float => $invoice->balance()),
        );
    }

    public function unsettledProformas(string $ownerId): DocumentTally
    {
        $proformas = Invoice::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->where('type', InvoiceType::Proforma->value)
            ->where('status', InvoiceStatus::Paid->value)
            ->whereNull('settled_invoice_id')
            ->get();

        return new DocumentTally(
            count: $proformas->count(),
            amount: (float) $proformas->sum('total'),
        );
    }

    public function pendingInboxCount(string $ownerId): int
    {
        return InvoiceInboxItem::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->where('status', InvoiceInboxStatus::Pending->value)
            ->count();
    }

    public function settledBankReferences(string $ownerId): array
    {
        /** @var list<string> */
        return InvoicePayment::query()
            ->whereNotNull('bank_reference')
            ->whereRelation('invoice', 'invoices.user_id', $ownerId)
            ->pluck('bank_reference')
            ->map(static fn (mixed $reference): string => (string) $reference)
            ->values()
            ->all();
    }
}
