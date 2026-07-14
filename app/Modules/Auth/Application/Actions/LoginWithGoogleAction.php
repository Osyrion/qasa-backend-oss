<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\Results\LoginResult;
use App\Modules\Auth\Application\Services\TwoFactorChallengeStore;
use App\Modules\Auth\Domain\Events\UserRegistered;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
    public function execute(SocialiteUser $googleUser, ?string $deviceName = null): LoginResult
    {
        $user = DB::transaction(function () use ($googleUser): User {
            /** @var class-string<User> $model */
            $model = config('auth.providers.users.model', User::class);

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
                    'email' => 'Účet s týmto e-mailom neexistuje.',
                ]);
            }

            // New user via Google
            [$name, $surname] = $this->parseName($googleUser->getName());

            $user = $model::create([
                'name' => $name,
                'surname' => $surname,
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'avatar_path' => $googleUser->getAvatar(),
                'email_verified_at' => now(),
                'default_currency' => Currency::EUR,
                'locale' => 'sk',
                'country' => 'SK',
                'invoice_prefix' => 'FA',
                'is_vat_payer' => false,
                'tax_flat_rate' => 0,
            ]);

            event(new UserRegistered($user));

            return $user;
        });

        if ($user->hasTwoFactorEnabled()) {
            return LoginResult::twoFactorChallenge($this->challengeStore->issue($user));
        }

        $deviceName = $deviceName ?? 'google-oauth';
        $user->tokens()->where('name', $deviceName)->delete();

        return LoginResult::success($user->createToken($deviceName)->plainTextToken, $user);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseName(?string $fullName): array
    {
        $parts = explode(' ', trim((string) $fullName), 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
