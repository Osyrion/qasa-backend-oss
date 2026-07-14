<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Application\Contracts;

use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use Illuminate\Pagination\LengthAwarePaginator;

interface WorkLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, TimeEntry>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator;

    public function findById(string $id): ?TimeEntry;

    public function findByIdOrFail(string $id): TimeEntry;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): TimeEntry;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TimeEntry $timeEntry, array $data): TimeEntry;

    public function delete(TimeEntry $timeEntry): void;

    /**
     * Total hours for user in given year
     */
    public function totalHoursForYear(string $userId, int $year): float;

    /**
     * Total billable hours for user in given year
     */
    public function totalBillableHoursForYear(string $userId, int $year): float;
}
