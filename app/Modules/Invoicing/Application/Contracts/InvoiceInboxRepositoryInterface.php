<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use Illuminate\Pagination\LengthAwarePaginator;

interface InvoiceInboxRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, InvoiceInboxItem>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator;

    public function findByIdOrFail(string $id): InvoiceInboxItem;

    /**
     * Runs without an authenticated user (console scanner), so it bypasses
     * the HasUserScope global scope and is given the account id explicitly.
     */
    public function existsByHash(string $userId, string $hash): bool;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): InvoiceInboxItem;

    public function save(InvoiceInboxItem $item): InvoiceInboxItem;
}
