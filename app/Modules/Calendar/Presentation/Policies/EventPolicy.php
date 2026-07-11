<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Calendar\Domain\Models\Event;
use App\Modules\Shared\Policies\InteractsWithAccount;

class EventPolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('calendar.view');
    }

    public function view(User $user, Event $event): bool
    {
        return $this->sameAccount($user, $event->user_id) && $user->can('calendar.view');
    }

    public function create(User $user): bool
    {
        return $user->can('calendar.manage');
    }

    public function update(User $user, Event $event): bool
    {
        return $this->sameAccount($user, $event->user_id) && $user->can('calendar.manage');
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->sameAccount($user, $event->user_id) && $user->can('calendar.manage');
    }
}
