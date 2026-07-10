<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\Services\InvoiceNumberMask;
use Illuminate\Pagination\LengthAwarePaginator;

interface SupplierInvoiceRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator;

    public function findById(string $id): ?SupplierInvoice;

    public function findByIdOrFail(string $id): SupplierInvoice;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SupplierInvoice;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SupplierInvoice $supplierInvoice, array $data): SupplierInvoice;

    public function delete(SupplierInvoice $supplierInvoice): void;

    /**
     * Generate the next internal reference number for a user, formatted by
     * $mask. $start is the lower bound of the sequence; it never lowers a
     * sequence already in use.
     */
    public function nextInternalNumber(string $userId, InvoiceNumberMask $mask, int $start = 1): string;
}
