<?php

declare(strict_types=1);

namespace App\Modules\Clients\Presentation\Policies;

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Policies\InteractsWithAccount;

class ClientPolicy
{
    use InteractsWithAccount;

    public function viewAny(Actor $user): bool
    {
        return $user->can('clients.view');
    }

    public function view(Actor $user, Client $client): bool
    {
        return $this->sameAccount($user, $client->user_id) && $user->can('clients.view');
    }

    public function create(Actor $user): bool
    {
        return $user->can('clients.manage');
    }

    public function update(Actor $user, Client $client): bool
    {
        return $this->sameAccount($user, $client->user_id) && $user->can('clients.manage');
    }

    public function delete(Actor $user, Client $client): bool
    {
        return $this->sameAccount($user, $client->user_id) && $user->can('clients.manage');
    }
}
