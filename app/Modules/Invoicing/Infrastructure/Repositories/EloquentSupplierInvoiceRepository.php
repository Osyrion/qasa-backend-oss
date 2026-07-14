<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Repositories;

use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\SupplierInvoiceExportData;
use App\Modules\Invoicing\Domain\Enums\ExportPeriodBasis;
use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceStatus;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\Services\InvoiceNumberMask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentSupplierInvoiceRepository implements SupplierInvoiceRepositoryInterface
{
    private const SORTABLE_COLUMNS = [
        'internal_number', 'supplier_invoice_number', 'status', 'issued_at', 'due_at',
        'subtotal', 'total', 'created_at',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, SupplierInvoice>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = SupplierInvoice::query()->with('client');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['search'])).'%';
            $query->where(function ($q) use ($term): void {
                $q->whereLike('internal_number', $term)
                    ->orWhereLike('supplier_invoice_number', $term);
            });
        }

        if (! empty($filters['date_from'])) {
            $query->where('issued_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('issued_at', '<=', $filters['date_to']);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE_COLUMNS, true)
            ? $filters['sort']
            : 'issued_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        return $query->paginate($perPage);
    }

    /**
     * @return Collection<int, SupplierInvoice>
     */
    public function forExport(SupplierInvoiceExportData $filter): Collection
    {
        $column = $filter->period_basis->column();

        $query = SupplierInvoice::query()
            ->whereNot('status', SupplierInvoiceStatus::Draft->value)
            ->whereBetween($column, [$filter->date_from, $filter->date_to]);

        if ($filter->period_basis === ExportPeriodBasis::Tax) {
            $query->whereNotNull('taxable_supply_at');
        }

        return $query
            ->with(['vatLines', 'client'])
            ->orderBy('issued_at')
            ->orderBy('supplier_invoice_number')
            ->get();
    }

    public function findById(string $id): ?SupplierInvoice
    {
        /** @var SupplierInvoice|null */
        return SupplierInvoice::find($id);
    }

    public function findByIdOrFail(string $id): SupplierInvoice
    {
        /** @var SupplierInvoice */
        return SupplierInvoice::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SupplierInvoice
    {
        /** @var SupplierInvoice */
        return SupplierInvoice::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SupplierInvoice $supplierInvoice, array $data): SupplierInvoice
    {
        $supplierInvoice->update($data);

        return $supplierInvoice->fresh() ?? $supplierInvoice;
    }

    public function delete(SupplierInvoice $supplierInvoice): void
    {
        $supplierInvoice->delete();
    }

    public function nextInternalNumber(string $userId, InvoiceNumberMask $mask, int $start = 1): string
    {
        $now = CarbonImmutable::now();

        // Serialize per account (runs inside the create transaction) —
        // concurrent requests would otherwise read the same max sequence.
        // The unique (user_id, internal_number) index is the last line of defense.
        DB::table('users')->where('id', $userId)->lockForUpdate()->value('id');

        $lastSequence = SupplierInvoice::withoutGlobalScope('user')
            ->withTrashed()
            ->where('user_id', $userId)
            ->where('internal_number', 'like', addcslashes($mask->likePrefix($now), '%_').'%')
            ->pluck('internal_number')
            ->map(fn (string $number): ?int => $mask->extractSequence($number, $now))
            ->filter(fn (?int $sequence): bool => $sequence !== null)
            ->max();

        // $start is a floor on the sequence — it only raises the next number,
        // never lowers it.
        $next = max((int) $lastSequence, max(1, $start) - 1) + 1;

        return $mask->format($next, $now);
    }
}
