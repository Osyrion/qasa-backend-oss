<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class Pagination
{
    public const DEFAULT_PER_PAGE = 20;

    /**
     * Upper bound on page size — an unbounded per_page lets a single
     * request hydrate the whole table.
     */
    public const MAX_PER_PAGE = 100;

    public static function perPage(Request $request, int $default = self::DEFAULT_PER_PAGE): int
    {
        return min(max((int) $request->input('per_page', $default), 1), self::MAX_PER_PAGE);
    }

    /**
     * Paginate a query under a *total* order.
     *
     * The one thing offset pagination cannot survive is ties. `ORDER BY
     * issued_at DESC LIMIT 20 OFFSET 20` is only well defined while no two
     * rows share an issued_at, and Postgres is explicit that it will not
     * invent an order for the ones that do: the same row may come back on
     * page one and page two while another is never shown at all.
     *
     * Ties are not the edge case they sound like here. issued_at and
     * expenses.date are `date` columns, so every invoice raised on a given day
     * ties — and recurring invoices are generated as a batch, on one day, by
     * one command. created_at is no refuge either: it is timestamp(0), and a
     * bulk import writes a whole page inside one second.
     *
     * So every paginated list goes through here and gets the primary key
     * appended as a final tiebreaker. Ids are UUIDv7, which is
     * time-ordered, so "newest first" stays true within a tie rather than
     * becoming arbitrary. Postgres 13+ plans the extra key as an incremental
     * sort on top of whatever index served the leading columns, so this
     * costs a sort of each tie group and nothing more.
     *
     * tests/Architecture/StablePaginationTest.php is what keeps this the only
     * way to paginate.
     *
     * Takes a Builder, not a Relation: a relation's declaring-model template
     * is not covariant, so a union of the two accepts no concrete HasMany at
     * all. Callers paginating a relation hand over its ->getQuery().
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Request|int  $perPage  a request to read per_page from, or the size itself
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function of(Builder $query, Request|int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        return self::withTotalOrder($query)
            ->paginate($perPage instanceof Request ? self::perPage($perPage) : $perPage);
    }

    /**
     * Appends the primary key unless the query is already ordered by it.
     *
     * Direction follows the last ordering the caller asked for: a list sorted
     * newest-first should break its ties newest-first too, or the tiebreaker
     * reads backwards inside each group.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function withTotalOrder(Builder $query): Builder
    {
        $model = $query->getModel();
        $key = $model->getKeyName();
        $qualified = $model->getQualifiedKeyName();

        /** @var list<array<string, mixed>> $orders */
        $orders = $query->getQuery()->orders ?? [];

        foreach ($orders as $order) {
            $column = $order['column'] ?? null;

            if ($column === $key || $column === $qualified) {
                return $query;
            }
        }

        $last = $orders === [] ? null : $orders[array_key_last($orders)];
        $direction = ($last['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($qualified, $direction);
    }
}
