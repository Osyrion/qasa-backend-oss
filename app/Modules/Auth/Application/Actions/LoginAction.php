<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\LoginData;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Hash;

class LoginAction
{
    /**
     * @return array{token: string, user: User}
     *
     * @throws DomainException
     */
    public function execute(LoginData $data): array
    {
        $user = User::where('email', $data->email)->first();

        if (! $user) {
            throw DomainException::because(__('auth.invalid_credentials'));
        }

        if ($user->password === null) {
            throw DomainException::because(__('auth.google_account'));
        }

        if (! Hash::check($data->password, $user->password)) {
            throw DomainException::because(__('auth.invalid_credentials'));
        }

        $deviceName = $data->device_name ?? 'api-token';

        // Revoke old tokens with same device name to avoid accumulation
        $user->tokens()->where('name', $deviceName)->delete();

        return [
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => $user,
        ];
    }
}
