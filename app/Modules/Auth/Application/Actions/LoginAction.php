<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\LoginData;
use App\Modules\Auth\Application\Results\LoginResult;
use App\Modules\Auth\Application\Services\LoginCaptchaGate;
use App\Modules\Auth\Application\Services\LoginThrottle;
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
        private readonly LoginThrottle $throttle,
        private readonly LoginCaptchaGate $captchaGate,
    ) {}

    /**
     * @throws DomainException
     */
    public function execute(LoginData $data): LoginResult
    {
        $ip = request()->ip();

        // Ahead of everything else: an attempt that is already waiting out a
        // backoff must not cost a database round-trip, and must answer the
        // same way whether or not the address belongs to a real account.
        $this->throttle->check($data->email, $ip);

        // Both gates answer before the credentials are looked at, so neither
        // reveals whether the guess was any good — and so an attacker pays
        // the captcha per guess rather than per success. Thrown from out
        // here rather than inside the try below on purpose: a refused
        // challenge is not a failed password and must not count as one.
        $this->captchaGate->ensureSolved($data->email, $data->turnstile_token, $ip);

        try {
            $result = $this->attempt($data);
        } catch (DomainException $e) {
            // Every outcome that is not a password this account accepts
            // counts, including an unknown e-mail and a suspended account.
            // Counting only the real ones would turn the backoff itself into
            // the enumeration oracle the rest of this class avoids being.
            $this->throttle->recordFailure($data->email, $ip);
            $this->captchaGate->recordFailure($data->email);

            throw $e;
        }

        // A correct password clears the slate — including on the 2FA branch,
        // where this class has done its job and the one-shot challenge takes
        // over. Without this a legitimate sign-in would leave the count
        // standing and the sixth honest login of the week would be refused.
        $this->throttle->clear($data->email, $ip);
        $this->captchaGate->clear($data->email);

        return $result;
    }

    /**
     * @throws DomainException
     */
    private function attempt(LoginData $data): LoginResult
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

        // Credentials check out, but the operator has suspended the account.
        // Refused here as well as in the `auth` middleware so a suspended
        // account cannot mint a fresh token it would only fail to use.
        if ($user->isSuspended()) {
            event(new LoginFailed($user, 'account_suspended'));

            throw DomainException::because(__('auth.account_suspended'));
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
