<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Infrastructure\Repositories;

use App\Modules\Calendar\Application\Contracts\EventRepositoryInterface;
use App\Modules\Calendar\Domain\Enums\EventSource;
use App\Modules\Calendar\Domain\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class EloquentEventRepository implements EventRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Event>
     */
    public function paginate(int $perPage = 20, ?Carbon $from = null, ?Carbon $to = null, ?string $orderId = null): LengthAwarePaginator
    {
        $query = Event::query()->with('order.client');

        $this->applyRange($query, $from, $to);

        if ($orderId !== null) {
            $query->where('order_id', $orderId);
        }

        $query->orderBy('starts_at');

        return $query->paginate($perPage);
    }

    public function findById(string $id): ?Event
    {
        /** @var Event|null */
        return Event::find($id);
    }

    public function findByIdOrFail(string $id): Event
    {
        /** @var Event */
        return Event::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Event
    {
        /** @var Event */
        return Event::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Event $event, array $data): Event
    {
        $event->update($data);

        return $event->fresh() ?? $event;
    }

    public function delete(Event $event): void
    {
        $event->delete();
    }

    public function existsExternal(string $userId, EventSource $source, string $externalUid): bool
    {
        return Event::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('source', $source)
            ->where('external_uid', $externalUid)
            ->exists();
    }

    public function forExport(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $query = Event::query();

        $this->applyRange($query, $from, $to);

        return $query->orderBy('starts_at')->get();
    }

    public function purgeEndingBefore(CarbonImmutable $cutoff): int
    {
        return Event::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('ends_at', '<', $cutoff)
            ->forceDelete();
    }

    /**
     * @param  Builder<Event>  $query
     */
    private function applyRange(Builder $query, ?Carbon $from, ?Carbon $to): void
    {
        if ($to !== null) {
            $query->where('starts_at', '<', $to->clone()->addDay());
        }

        if ($from !== null) {
            $query->where('ends_at', '>', $from);
        }
    }
}
