<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Repositories;

use App\Modules\Invoicing\Application\Contracts\QuoteRepositoryInterface;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Services\InvoiceNumberMask;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentQuoteRepository implements QuoteRepositoryInterface
{
    private const SORTABLE_COLUMNS = ['quote_number', 'status', 'issued_at', 'valid_until', 'total', 'created_at'];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Quote>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = Quote::query()->with('client');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
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

    public function findById(string $id): ?Quote
    {
        /** @var Quote|null */
        return Quote::find($id);
    }

    public function findByIdOrFail(string $id): Quote
    {
        /** @var Quote */
        return Quote::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Quote
    {
        /** @var Quote */
        return Quote::create($data);
    }

    public function delete(Quote $quote): void
    {
        $quote->delete();
    }

    public function nextQuoteNumber(string $userId, InvoiceNumberMask $mask, int $start = 1): string
    {
        $now = CarbonImmutable::now();

        // Serialize per account (runs inside the create transaction) —
        // concurrent requests would otherwise read the same max sequence.
        // The unique (user_id, quote_number) index is the last line of defense.
        DB::table('users')->where('id', $userId)->lockForUpdate()->value('id');

        $lastSequence = Quote::withoutGlobalScope('user')
            ->withTrashed()
            ->where('user_id', $userId)
            ->where('quote_number', 'like', addcslashes($mask->likePrefix($now), '%_').'%')
            ->pluck('quote_number')
            ->map(fn (string $number): ?int => $mask->extractSequence($number, $now))
            ->filter(fn (?int $sequence): bool => $sequence !== null)
            ->max();

        // $start is a floor on the sequence — it only raises the next number,
        // never lowers it.
        $next = max((int) $lastSequence, max(1, $start) - 1) + 1;

        return $mask->format($next, $now);
    }
}
