<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Application\Contracts;

use App\Modules\TimeTracking\Domain\Models\Expense;
use Illuminate\Pagination\LengthAwarePaginator;

interface ExpenseRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator;

    public function findById(string $id): ?Expense;

    public function findByIdOrFail(string $id): Expense;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Expense;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data): Expense;

    public function delete(Expense $expense): void;

    /**
     * Sum of expenses for user in given year, in their default currency.
     */
    public function totalForYear(string $userId, int $year): float;
}
