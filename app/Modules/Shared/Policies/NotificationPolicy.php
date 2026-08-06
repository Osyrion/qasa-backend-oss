<?php

declare(strict_types=1);

namespace App\Modules\Shared\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Domain\Models\AccountNotification;

/**
 * The one policy in the codebase that deliberately does **not** key on the
 * account.
 *
 * Everywhere else `sameAccount()` is the whole question, because the data
 * belongs to the account and every member of it may see the same invoices.
 * A notification belongs to a person: "your VAT return is due" addressed to
 * the owner is not the accountant's to read, and a member's notifications
 * are not the owner's either. Both rows live in the same account, so
 * sameAccount() would wave both through — the check has to be the recipient
 * itself.
 *
 * There is no ability gate for the same reason: no permission grants you
 * someone else's inbox, and every user may read their own.
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AccountNotification $notification): bool
    {
        return $this->isRecipient($user, $notification);
    }

    public function update(User $user, AccountNotification $notification): bool
    {
        return $this->isRecipient($user, $notification);
    }

    public function delete(User $user, AccountNotification $notification): bool
    {
        return $this->isRecipient($user, $notification);
    }

    private function isRecipient(User $user, AccountNotification $notification): bool
    {
        return $notification->notifiable_id === $user->id;
    }
}
