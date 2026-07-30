<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\LoginData;
use App\Modules\Auth\Application\Results\LoginResult;
use App\Modules\Auth\Application\Services\TwoFactorChallengeStore;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\AccountLookup;
use Illuminate\Support\Facades\Hash;

class LoginAction
{
    public function __construct(
        private readonly TwoFactorChallengeStore $challengeStore,
    ) {}

    /**
     * @throws DomainException
     */
    public function execute(LoginData $data): LoginResult
    {
        // users is now tenant-scoped, and nothing is bound at login — the
        // row must be found via the SECURITY DEFINER lookup before the real
        // query below can see it at all.
        AccountLookup::bindByEmail($data->email);

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

        // Password is correct but 2FA is on — hand back a short-lived
        // challenge instead of a token; the client completes login via
        // POST /auth/2fa/verify.
        if ($user->hasTwoFactorEnabled()) {
            return LoginResult::twoFactorChallenge($this->challengeStore->issue($user));
        }

        $deviceName = $data->device_name ?? 'api-token';

        // Revoke old tokens with same device name to avoid accumulation
        $user->tokens()->where('name', $deviceName)->delete();

        return LoginResult::success($user->createToken($deviceName)->plainTextToken, $user);
    }
}
