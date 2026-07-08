<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Policies\InteractsWithAccount;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;

class TimeEntryPolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('timetracking.view');
    }

    public function view(User $user, TimeEntry $timeEntry): bool
    {
        return $this->sameAccount($user, $timeEntry->user_id) && $user->can('timetracking.view');
    }

    public function create(User $user): bool
    {
        return $user->can('timetracking.manage');
    }

    public function update(User $user, TimeEntry $timeEntry): bool
    {
        return $this->sameAccount($user, $timeEntry->user_id) && $user->can('timetracking.manage');
    }

    public function delete(User $user, TimeEntry $timeEntry): bool
    {
        return $this->sameAccount($user, $timeEntry->user_id) && $user->can('timetracking.manage');
    }
}
