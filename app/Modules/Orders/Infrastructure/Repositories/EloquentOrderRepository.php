<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Repositories;

use App\Modules\Orders\Application\Contracts\OrderRepositoryInterface;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Support\Pagination;
use App\Modules\Shared\Support\Search;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentOrderRepository implements OrderRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        // user as well as client: OrderResource asks every row for
        // effectiveCurrency(), which falls back through the client to the
        // account's own default. An order with no currency of its own — the
        // normal case — was one query per row.
        $query = Order::query()->with(['client', 'user']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (isset($filters['personal']) && $filters['personal']) {
            $query->personal();
        }

        if (isset($filters['billable']) && $filters['billable']) {
            $query->billable();
        }

        if (! empty($filters['billing_type'])) {
            $query->where('billing_type', $filters['billing_type']);
        }

        if (! empty($filters['search'])) {
            // Grouped even though there is only one predicate today: an
            // orWhere added here later would otherwise escape the preceding
            // filters and widen the result set.
            $term = Search::term((string) $filters['search']);
            $query->where(function (Builder $q) use ($term): void {
                $q->whereRaw(Search::unaccentedLike('name'), [$term]);
            });
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        $query->orderBy($sort, $direction);

        return Pagination::of($query, $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginateForClient(string $clientId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = Order::query()
            ->where('client_id', $clientId)
            ->with(['client', 'user']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $query->orderBy('created_at', 'desc');

        return Pagination::of($query, $perPage);
    }

    public function findById(string $id): ?Order
    {
        /** @var Order|null */
        return Order::find($id);
    }

    public function findByIdOrFail(string $id): Order
    {
        /** @var Order */
        return Order::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Order
    {
        /** @var Order */
        return Order::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Order $order, array $data): Order
    {
        $order->update($data);

        return $order->fresh() ?? $order;
    }

    public function delete(Order $order): void
    {
        $order->delete();
    }

    public function countForUser(string $userId): int
    {
        return Order::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->count();
    }
}
