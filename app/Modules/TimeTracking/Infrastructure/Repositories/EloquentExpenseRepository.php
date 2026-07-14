<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Infrastructure\Repositories;

use App\Modules\TimeTracking\Application\Contracts\ExpenseRepositoryInterface;
use App\Modules\TimeTracking\Domain\Models\Expense;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentExpenseRepository implements ExpenseRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Expense>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = Expense::query();

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }

        if (! empty($filters['year'])) {
            $query->whereYear('date', $filters['year']);
        }

        $query->orderBy('date', 'desc');

        return $query->paginate($perPage);
    }

    public function findById(string $id): ?Expense
    {
        /** @var Expense|null */
        return Expense::find($id);
    }

    public function findByIdOrFail(string $id): Expense
    {
        /** @var Expense */
        return Expense::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Expense
    {
        /** @var Expense */
        return Expense::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data): Expense
    {
        $expense->update($data);

        return $expense->fresh() ?? $expense;
    }

    public function delete(Expense $expense): void
    {
        $expense->delete();
    }

    public function totalForYear(string $userId, int $year): float
    {
        return (float) Expense::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereYear('date', $year)
            ->sum('amount');
    }
}
