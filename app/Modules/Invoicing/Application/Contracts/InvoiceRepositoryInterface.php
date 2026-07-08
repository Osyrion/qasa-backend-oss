<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\Invoice;
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
     * Generate next invoice number for user.
     * Format: {prefix}-{year}-{sequence} e.g. FA-2025-001
     */
    public function nextInvoiceNumber(string $userId, string $prefix): string;
}
