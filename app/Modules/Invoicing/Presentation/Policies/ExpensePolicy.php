<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Shared\Policies\InteractsWithAccount;

class ExpensePolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('expenses.view');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->sameAccount($user, $expense->user_id) && $user->can('expenses.view');
    }

    public function create(User $user): bool
    {
        return $user->can('expenses.manage');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->sameAccount($user, $expense->user_id) && $user->can('expenses.manage');
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->sameAccount($user, $expense->user_id) && $user->can('expenses.manage');
    }
}
