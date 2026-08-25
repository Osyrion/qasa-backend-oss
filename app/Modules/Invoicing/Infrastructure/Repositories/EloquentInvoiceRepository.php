<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Repositories;

use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\TrackedWorkLinkInterface;
use App\Modules\Invoicing\Application\DTOs\InvoiceExportData;
use App\Modules\Invoicing\Domain\Enums\ExportPeriodBasis;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Invoicing\Domain\ValueObjects\InvoiceNumberMask;
use App\Modules\Shared\Support\Search;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentInvoiceRepository implements InvoiceRepositoryInterface
{
    private const SORTABLE_COLUMNS = [
        'invoice_number', 'variable_symbol', 'type', 'status', 'issued_at', 'due_at',
        'subtotal', 'total', 'created_at',
    ];

    public function __construct(private readonly TrackedWorkLinkInterface $trackedWork) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = Invoice::query()
            ->with('client')
            ->withSum('payments', 'amount');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (! empty($filters['order_id'])) {
            // An invoice belongs to an order through its lines — either via
            // billed order items or via billed time entries.
            $orderId = $filters['order_id'];
            $query->whereHas('items', function ($items) use ($orderId): void {
                $items->where(function (Builder $item) use ($orderId): void {
                    /** @var Builder<InvoiceItem> $item */
                    $item->whereHas('orderItem', fn ($q) => $q->where('order_id', $orderId));

                    $trackedWorkIds = $this->trackedWork->workIdsForOrder($orderId);

                    if ($trackedWorkIds !== null) {
                        $item->orWhereIn('time_entry_id', $trackedWorkIds);
                    }
                });
            });
        }

        if (! empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $term = Search::term($search);
            // Prose goes through full text, which is indexed and ignores
            // diacritics; identifiers and names stay on LIKE, where a
            // substring match is what people expect of a number or a surname.
            $fullText = Search::fullTextTerm($search);

            $query->where(function ($q) use ($term, $fullText): void {
                $q->whereLike('invoice_number', $term)
                    ->orWhereLike('variable_symbol', $term)
                    ->orWhereHas('client', function ($client) use ($term): void {
                        $client->whereRaw(Search::unaccentedLike('name'), [$term])
                            ->orWhereRaw(Search::unaccentedLike('surname'), [$term])
                            ->orWhereRaw(Search::unaccentedLike('company_name'), [$term]);
                    });

                if ($fullText !== null) {
                    $q->orWhereRaw(Search::fullTextMatch('note', 'note_above'), [$fullText])
                        ->orWhereHas('items', fn ($item) => $item->whereRaw(
                            Search::fullTextMatch('description'), [$fullText],
                        ));
                }
            });
        }

        if (! empty($filters['date_from'])) {
            $query->where('issued_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('issued_at', '<=', $filters['date_to']);
        }

        if (isset($filters['overdue']) && $filters['overdue']) {
            $query->overdue();
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE_COLUMNS, true)
            ? $filters['sort']
            : 'issued_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sort === 'invoice_number') {
            // Drafts (invoice_number IS NULL) always sort last regardless of
            // direction — Postgres' default NULL-first on DESC would
            // otherwise surface undrafted invoices ahead of numbered ones.
            $query->orderByRaw('invoice_number IS NULL')
                ->orderBy($sort, $direction);
        } else {
            $query->orderBy($sort, $direction);
        }

        return $query->paginate($perPage);
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function forExport(InvoiceExportData $filter): Collection
    {
        $column = $filter->period_basis->column();

        $query = Invoice::query()
            ->whereNot('status', InvoiceStatus::Draft->value)
            ->whereIn('type', $filter->types)
            ->whereBetween($column, [$filter->date_from, $filter->date_to]);

        if ($filter->period_basis === ExportPeriodBasis::Tax) {
            $query->whereNotNull('taxable_supply_at');
        }

        return $query
            ->with(['items', 'client', 'payments', 'bankAccount'])
            ->orderBy('issued_at')
            ->orderBy('invoice_number')
            ->get();
    }

    public function findById(string $id): ?Invoice
    {
        /** @var Invoice|null */
        return Invoice::find($id);
    }

    public function findByIdOrFail(string $id): Invoice
    {
        /** @var Invoice */
        return Invoice::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Invoice
    {
        /** @var Invoice */
        return Invoice::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        $invoice->update($data);

        return $invoice->fresh() ?? $invoice;
    }

    public function delete(Invoice $invoice): void
    {
        $invoice->delete();
    }

    public function nextInvoiceNumber(string $userId, InvoiceNumberMask $mask, int $start = 1): string
    {
        $now = CarbonImmutable::now();

        // Serialize per account (runs inside the create transaction) —
        // concurrent requests would otherwise read the same max sequence.
        // The unique (user_id, invoice_number) index is the last line of defense.
        DB::table('users')->where('id', $userId)->lockForUpdate()->value('id');

        // Max is computed numerically in PHP — lexicographic ordering breaks
        // once the sequence passes 999 ("999" > "1000"). Trashed invoices are
        // included so their numbers are never reissued. The LIKE filter is
        // scoped to the mask's current-period prefix, so a different date
        // literal (year/month rollover) or type prefix naturally starts a
        // fresh, independent sequence.
        $lastSequence = Invoice::withoutGlobalScope('user')
            ->withTrashed()
            ->where('user_id', $userId)
            ->where('invoice_number', 'like', addcslashes($mask->likePrefix($now), '%_').'%')
            ->pluck('invoice_number')
            ->map(fn (string $number): ?int => $mask->extractSequence($number, $now))
            ->filter(fn (?int $sequence): bool => $sequence !== null)
            ->max();

        // $start is a floor on the sequence (e.g. to continue numbering after
        // a migration) — it only raises the next number, never lowers it.
        $next = max((int) $lastSequence, max(1, $start) - 1) + 1;

        return $mask->format($next, $now);
    }
}
