<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\ExpenseHistory;
use App\Modules\Invoicing\Domain\Enums\ExpenseCategory;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\ValueObjects\CategorisedExpense;

final class EloquentExpenseHistory implements ExpenseHistory
{
    public function recentlyCategorised(string $ownerId, int $limit): array
    {
        return array_values(Expense::query()
            ->forUser($ownerId)
            ->orderByDesc('date')
            ->limit($limit)
            ->get(['description', 'category'])
            ->map(static function (Expense $expense): ?CategorisedExpense {
                $category = ExpenseCategory::tryFrom($expense->category);

                return $category === null
                    ? null
                    : new CategorisedExpense($expense->description, $category);
            })
            ->filter()
            ->all());
    }
}
