<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\LoginData;
use App\Modules\Auth\Application\Results\LoginResult;
use App\Modules\Auth\Application\Services\TwoFactorChallengeStore;
use App\Modules\Auth\Domain\Events\LoginFailed;
use App\Modules\Auth\Domain\Events\LoginSucceeded;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\AccountLookup;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

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

        // Querying the core class directly (rather than the edition's bound
        // provider model, as RegisterUserAction/LoginWithGoogleAction/
        // TwoFactorChallengeStore all do) returns a real row but the wrong
        // PHP class in the SaaS edition. That mismatch doesn't show up here —
        // it surfaces one step later: the token this class mints for that
        // instance gets a tokenable_type Sanctum's own provider check then
        // rejects on every subsequent bearer-authenticated request.
        /** @var class-string<User> $model */
        $model = config('auth.providers.users.model', User::class);

        $user = $model::where('email', $data->email)->first();

        if (! $user) {
            // No tenant to attach an activity_log row to, and logging one
            // against a real account here would make this endpoint an
            // email-enumeration oracle. Goes to a dedicated channel instead.
            Log::channel('security')->warning('login attempt: unknown email', [
                'email' => $data->email,
                'ip' => request()->ip(),
            ]);

            throw DomainException::because(__('auth.invalid_credentials'));
        }

        if ($user->password === null) {
            event(new LoginFailed($user, 'google_account'));

            throw DomainException::because(__('auth.google_account'));
        }

        if (! Hash::check($data->password, $user->password)) {
            event(new LoginFailed($user, 'invalid_password'));

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

        $token = $user->createSessionToken($deviceName, request()->ip(), request()->userAgent());

        event(new LoginSucceeded($user));

        return LoginResult::success($token->plainTextToken, $user);
    }
}
