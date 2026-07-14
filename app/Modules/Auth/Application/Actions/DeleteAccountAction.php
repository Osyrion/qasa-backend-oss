<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\DeleteAccountData;
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
            if ($data->password === null || ! Hash::check($data->password, $user->password)) {
                throw DomainException::because(__('auth.invalid_password'));
            }
        } elseif ($data->confirmation !== 'DELETE') {
            throw DomainException::because(__('auth.invalid_delete_confirmation'));
        }

        $user->tokens()->delete();
        $user->delete();
    }
}
