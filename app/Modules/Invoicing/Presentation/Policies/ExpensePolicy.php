<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Policies\InteractsWithAccount;

/**
 * An expense is an accounting document that feeds the tax base, so it is
 * gated by `invoices.*` like every other record this module owns.
 *
 * It used to check `timetracking.*` — the only Invoicing policy that did,
 * and almost certainly copied from TimeEntryPolicy. The effect was not
 * cosmetic: the Member role carries timetracking.manage and deliberately
 * does *not* carry invoices.manage, so every Member could create, edit and
 * delete the account's expenses.
 */
class ExpensePolicy
{
    use InteractsWithAccount;

    public function viewAny(Actor $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(Actor $user, Expense $expense): bool
    {
        return $this->sameAccount($user, $expense->user_id) && $user->can('invoices.view');
    }

    public function create(Actor $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(Actor $user, Expense $expense): bool
    {
        return $this->sameAccount($user, $expense->user_id) && $user->can('invoices.manage');
    }

    public function delete(Actor $user, Expense $expense): bool
    {
        return $this->sameAccount($user, $expense->user_id) && $user->can('invoices.manage');
    }
}
