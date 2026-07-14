<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Repositories;

use App\Modules\Invoicing\Application\Contracts\InvoiceInboxRepositoryInterface;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentInvoiceInboxRepository implements InvoiceInboxRepositoryInterface
{
    private const SORTABLE_COLUMNS = ['scanned_at', 'status', 'original_filename', 'created_at'];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, InvoiceInboxItem>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = InvoiceInboxItem::query()->with(['matchedClient', 'supplierInvoice']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('scanned_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('scanned_at', '<=', $filters['date_to']);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE_COLUMNS, true)
            ? $filters['sort']
            : 'scanned_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        return $query->paginate($perPage);
    }

    public function findByIdOrFail(string $id): InvoiceInboxItem
    {
        /** @var InvoiceInboxItem */
        return InvoiceInboxItem::findOrFail($id);
    }

    public function existsByHash(string $userId, string $hash): bool
    {
        return InvoiceInboxItem::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('file_hash', $hash)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): InvoiceInboxItem
    {
        /** @var InvoiceInboxItem */
        return InvoiceInboxItem::create($data);
    }

    public function save(InvoiceInboxItem $item): InvoiceInboxItem
    {
        $item->save();

        return $item;
    }
}
