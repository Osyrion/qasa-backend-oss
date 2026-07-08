<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Policies\InteractsWithAccount;
use App\Modules\TimeTracking\Domain\Models\Expense;

class ExpensePolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('timetracking.view');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->sameAccount($user, $expense->user_id) && $user->can('timetracking.view');
    }

    public function create(User $user): bool
    {
        return $user->can('timetracking.manage');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->sameAccount($user, $expense->user_id) && $user->can('timetracking.manage');
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->sameAccount($user, $expense->user_id) && $user->can('timetracking.manage');
    }
}
