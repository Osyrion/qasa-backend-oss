<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Integrations\Domain\Models\WebhookEndpoint;
use App\Modules\Shared\Policies\InteractsWithAccount;

class WebhookEndpointPolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('integrations.manage');
    }

    public function view(User $user, WebhookEndpoint $webhookEndpoint): bool
    {
        return $this->sameAccount($user, $webhookEndpoint->user_id) && $user->can('integrations.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('integrations.manage');
    }

    public function update(User $user, WebhookEndpoint $webhookEndpoint): bool
    {
        return $this->sameAccount($user, $webhookEndpoint->user_id) && $user->can('integrations.manage');
    }

    public function delete(User $user, WebhookEndpoint $webhookEndpoint): bool
    {
        return $this->sameAccount($user, $webhookEndpoint->user_id) && $user->can('integrations.manage');
    }
}
