<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Half-authenticated state between "password correct" and "2FA code
 * verified" — a cache record (not a Sanctum token), so a login stuck at
 * this stage has zero API access. One-time and short-lived: consume()
 * removes the record on read (Cache::pull), and it expires on its own
 * after TTL_MINUTES regardless.
 */
class TwoFactorChallengeStore
{
    private const TTL_MINUTES = 5;

    private const PREFIX = 'two-factor-challenge:';

    public function issue(User $user): string
    {
        $token = Str::random(64);

        Cache::put(self::PREFIX.$token, $user->id, now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    public function consume(string $challengeToken): ?User
    {
        $userId = Cache::pull(self::PREFIX.$challengeToken);

        if (! is_string($userId)) {
            return null;
        }

        /** @var class-string<User> $model */
        $model = config('auth.providers.users.model', User::class);

        return $model::find($userId);
    }
}
