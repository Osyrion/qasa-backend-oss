<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\ExpenseAuthorization;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Shared\Domain\Contracts\Actor;
use Illuminate\Support\Facades\Gate;

/**
 * Answers by asking ExpensePolicy, so the rule has one home.
 */
final readonly class GateExpenseAuthorization implements ExpenseAuthorization
{
    public function allowsCreate(Actor $actor): bool
    {
        return Gate::forUser($actor)->allows('create', Expense::class);
    }
}
