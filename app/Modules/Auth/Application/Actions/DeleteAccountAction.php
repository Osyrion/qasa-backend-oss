<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\DeleteAccountData;
use App\Modules\Auth\Domain\Events\AccountDeleted;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Hash;

class DeleteAccountAction
{
    /**
     * @throws DomainException
     */
    public function execute(User $user, DeleteAccountData $data): void
    {
        if ($user->hasPassword()) {
            if ($data->password === null || ! Hash::check($data->password, (string) $user->password)) {
                throw DomainException::because(__('auth.invalid_password'));
            }
        } elseif ($data->confirmation !== 'DELETE') {
            throw DomainException::because(__('auth.invalid_delete_confirmation'));
        }

        // Fired before the (soft) delete so the subject is still fully
        // hydrated. activity_log.user_id cascades on a hard delete, not a
        // soft one, and PurgeDeletedAccountsAction — the second half of this,
        // once the grace period expires — anonymises the row rather than
        // deleting it, precisely so the log documenting the account outlives
        // the identity behind it.
        //
        // The premium Team module listens here to soft-delete members
        // alongside their owner; a soft delete does not cascade on its own.
        event(new AccountDeleted($user));

        $user->tokens()->delete();
        $user->delete();
    }
}
