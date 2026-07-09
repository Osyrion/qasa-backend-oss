<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Application\DTOs\InvoiceExportData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Services\InvoiceNumberMask;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface InvoiceRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator;

    /**
     * All non-draft invoices matching the export filter, unpaginated,
     * ordered for accountant handoff. Eager-loads items/client/payments/
     * bankAccount to avoid N+1 across the whole matched period.
     *
     * @return Collection<int, Invoice>
     */
    public function forExport(InvoiceExportData $filter): Collection;

    public function findById(string $id): ?Invoice;

    public function findByIdOrFail(string $id): Invoice;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Invoice;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Invoice $invoice, array $data): Invoice;

    public function delete(Invoice $invoice): void;

    /**
     * Generate the next invoice number for a user, formatted by $mask.
     * $start is the lower bound of the sequence (e.g. to continue numbering
     * from a migrated system); it never lowers a sequence already in use.
     */
    public function nextInvoiceNumber(string $userId, InvoiceNumberMask $mask, int $start = 1): string;
}
