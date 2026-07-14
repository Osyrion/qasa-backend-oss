<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;

class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('activity.view');
    }
}
