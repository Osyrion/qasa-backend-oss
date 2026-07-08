<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Services\InvoiceNumberMask;
use Illuminate\Pagination\LengthAwarePaginator;

interface InvoiceRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator;

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
