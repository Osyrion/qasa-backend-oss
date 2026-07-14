<?php

declare(strict_types=1);

namespace App\Modules\Clients\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Policies\InteractsWithAccount;

class ClientPolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('clients.view');
    }

    public function view(User $user, Client $client): bool
    {
        return $this->sameAccount($user, $client->user_id) && $user->can('clients.view');
    }

    public function create(User $user): bool
    {
        return $user->can('clients.manage');
    }

    public function update(User $user, Client $client): bool
    {
        return $this->sameAccount($user, $client->user_id) && $user->can('clients.manage');
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->sameAccount($user, $client->user_id) && $user->can('clients.manage');
    }
}
