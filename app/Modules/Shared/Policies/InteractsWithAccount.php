<?php

declare(strict_types=1);

namespace App\Modules\Shared\Policies;

use App\Modules\Auth\Domain\Models\User;

trait InteractsWithAccount
{
    /**
     * Business records are owned by the account owner's user_id — a team
     * member belongs to the same account when the owner ids match.
     */
    protected function sameAccount(User $user, ?string $recordUserId): bool
    {
        return $recordUserId !== null && $user->accountOwnerId() === (string) $recordUserId;
    }
}
