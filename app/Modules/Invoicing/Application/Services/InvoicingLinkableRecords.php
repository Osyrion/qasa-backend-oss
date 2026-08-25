<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Application\Contracts\LinkableRecordResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * The three kinds of document Invoicing owns, answered for whoever wants to
 * point at one — the Documents module attaching a scan, today.
 */
final class InvoicingLinkableRecords implements LinkableRecordResolver
{
    public function recordTypes(): array
    {
        return ['invoice', 'supplier_invoice', 'expense'];
    }

    public function existsForAccount(string $recordType, string $recordId, string $ownerId): bool
    {
        $model = match ($recordType) {
            'invoice' => new Invoice,
            'supplier_invoice' => new SupplierInvoice,
            'expense' => new Expense,
            default => null,
        };

        if (! $model instanceof Model) {
            return false;
        }

        // The scope is dropped and the owner compared explicitly, rather than
        // trusting whoever happens to be authenticated: the caller passes the
        // account it is acting for, and this has to answer the same way from a
        // queue job as from a request.
        return $model->newQuery()
            ->withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->whereKey($recordId)
            ->exists();
    }
}
