<?php

declare(strict_types=1);

namespace App\Modules\Shared\Policies;

use App\Modules\Shared\Domain\Contracts\Account;

trait InteractsWithAccount
{
    /**
     * Business records are owned by the account owner's user_id — a team
     * member belongs to the same account when the owner ids match.
     *
     * Typed as the contract rather than the model for the reason HasUserScope
     * is: this trait is mixed into every policy in every module, and deptrac
     * flattens a trait's dependencies onto each class that uses it.
     */
    protected function sameAccount(Account $user, ?string $recordUserId): bool
    {
        return $recordUserId !== null && $user->accountOwnerId() === (string) $recordUserId;
    }
}
