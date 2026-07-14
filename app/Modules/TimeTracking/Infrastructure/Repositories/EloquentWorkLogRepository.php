<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Infrastructure\Repositories;

use App\Modules\TimeTracking\Application\Contracts\WorkLogRepositoryInterface;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentWorkLogRepository implements WorkLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, TimeEntry>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = TimeEntry::query();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['order_id'])) {
            $query->where('order_id', $filters['order_id']);
        }

        if (! empty($filters['is_billable'])) {
            $query->where('is_billable', $filters['is_billable']);
        }

        if (! empty($filters['is_invoiced'])) {
            $query->where('is_invoiced', $filters['is_invoiced']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('started_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('started_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['year'])) {
            $query->whereYear('started_at', $filters['year']);
        }

        $query->orderBy('started_at', 'desc');

        return $query->paginate($perPage);
    }

    public function findById(string $id): ?TimeEntry
    {
        /** @var TimeEntry|null */
        return TimeEntry::find($id);
    }

    public function findByIdOrFail(string $id): TimeEntry
    {
        /** @var TimeEntry */
        return TimeEntry::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): TimeEntry
    {
        /** @var TimeEntry */
        return TimeEntry::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TimeEntry $timeEntry, array $data): TimeEntry
    {
        $timeEntry->update($data);

        return $timeEntry->fresh() ?? $timeEntry;
    }

    public function delete(TimeEntry $timeEntry): void
    {
        $timeEntry->delete();
    }

    public function totalHoursForYear(string $userId, int $year): float
    {
        $totalSeconds = (int) TimeEntry::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereYear('started_at', $year)
            ->sum('duration_seconds');

        return round($totalSeconds / 3600, 2);
    }

    public function totalBillableHoursForYear(string $userId, int $year): float
    {
        $totalSeconds = (int) TimeEntry::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('is_billable', true)
            ->whereYear('started_at', $year)
            ->sum('duration_seconds');

        return round($totalSeconds / 3600, 2);
    }
}
