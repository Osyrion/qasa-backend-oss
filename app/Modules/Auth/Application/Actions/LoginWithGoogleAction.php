<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\Results\LoginResult;
use App\Modules\Auth\Application\Services\TwoFactorChallengeStore;
use App\Modules\Auth\Domain\Events\UserRegistered;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Support\AccountLookup;
use App\Modules\Shared\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Throwable;

class LoginWithGoogleAction
{
    public function __construct(
        private readonly TwoFactorChallengeStore $challengeStore,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(SocialiteUser $googleUser, ?string $deviceName = null, ?Request $request = null): LoginResult
    {
        // Google logins are keyed to an existing account by e-mail, so an
        // unverified Google e-mail must never be trusted — otherwise someone
        // could register a Google account on a victim's address and take over
        // the matching password account. Reject before any lookup or creation.
        if (! $this->googleEmailIsVerified($googleUser)) {
            throw ValidationException::withMessages([
                'email' => __('auth.google_email_unverified'),
            ]);
        }

        $user = DB::transaction(function () use ($googleUser, $request): User {
            /** @var class-string<User> $model */
            $model = config('auth.providers.users.model', User::class);

            // users is now tenant-scoped, and nothing is bound on an OAuth
            // callback — bind via the SECURITY DEFINER lookup before the
            // real query below can see the row at all.
            AccountLookup::bindByEmail($googleUser->getEmail());

            $existing = $model::where('email', $googleUser->getEmail())->first();

            if ($existing) {
                // Link Google ID if not already linked
                if (! $existing->google_id) {
                    $existing->update([
                        'google_id' => $googleUser->getId(),
                        'avatar_path' => $existing->avatar_path ?? $googleUser->getAvatar(),
                    ]);
                }

                return $existing;
            }

            // Without registration, Google can only log in or link existing
            // accounts — an unknown e-mail must not create one.
            if (! config('qasa.features.registration')) {
                throw ValidationException::withMessages([
                    'email' => __('auth.google_account_not_found'),
                ]);
            }

            // New user via Google
            [$name, $surname] = $this->parseName($googleUser->getName() ?? '');

            // The row about to be created is its own account — WITH CHECK
            // needs the id bound before the insert, not after, so it is
            // generated here instead of left to HasUuids. See
            // RegisterUserAction for the same fix, including why this has
            // to be forceCreate() rather than create().
            $id = (string) (new $model)->newUniqueId();
            TenantContext::set($id);

            $user = $model::query()->forceCreate([
                'id' => $id,
                'name' => $name,
                'surname' => $surname,
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'avatar_path' => $googleUser->getAvatar(),
                'email_verified_at' => now(),
                'default_currency' => Currency::EUR,
                'locale' => $this->preferredLocale($request),
                'is_vat_payer' => false,
                'tax_flat_rate' => 0,
            ]);

            // The SaaS Team module assigns the Owner role via a listener.
            event(new UserRegistered($user));

            return $user;
        });

        // A 2FA-enabled account must clear the challenge even when signing in
        // through Google — otherwise Google login would bypass 2FA entirely.
        if ($user->hasTwoFactorEnabled()) {
            return LoginResult::twoFactorChallenge($this->challengeStore->issue($user));
        }

        $deviceName = $deviceName ?? 'google-oauth';
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createSessionToken($deviceName, $request?->ip(), $request?->userAgent());

        return LoginResult::success($token->plainTextToken, $user);
    }

    /**
     * Whether Google asserts the account's e-mail is verified. The claim lives
     * in the raw userinfo payload (email_verified on OIDC, verified_email on
     * the legacy OAuth2 endpoint), which is only exposed on the concrete
     * Socialite user, not the contract.
     */
    private function googleEmailIsVerified(SocialiteUser $googleUser): bool
    {
        if (! $googleUser instanceof AbstractUser) {
            return false;
        }

        $raw = $googleUser->getRaw();

        $verified = $raw['email_verified'] ?? $raw['verified_email'] ?? false;

        return $verified === true || $verified === 'true' || $verified === 1 || $verified === '1';
    }

    /**
     * Accept-Language fallback, same as RegisterUserData — residency and
     * locale are independent axes, this is only a starting default.
     */
    private function preferredLocale(?Request $request): string
    {
        $available = config('qasa.locales.available', [config('app.locale')]);

        return $request?->getPreferredLanguage($available) ?? (string) config('app.fallback_locale');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);
        $name = $parts[0];
        $surname = $parts[1] ?? '';

        return [$name, $surname];
    }
}
