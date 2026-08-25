<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\CategorisedExpense;

/**
 * What an account has filed before, for something guessing what it would file
 * this under.
 *
 * A set crosses here and it is bounded the way `InvoiceLookup::openForMatching()`
 * is bounded: the newest N of one account's expenses, scored one at a time
 * against a description rather than summed. Anything that wants totals wants
 * `TurnoverAnalytics`, not this.
 */
interface ExpenseHistory
{
    /**
     * Newest first, at most $limit of them. Expenses with no usable category
     * are left out — a suggester cannot learn anything from them.
     *
     * @return list<CategorisedExpense>
     */
    public function recentlyCategorised(string $ownerId, int $limit): array;
}
