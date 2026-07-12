<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Results;

use App\Modules\Auth\Domain\Models\User;

/**
 * Outcome of LoginAction/LoginWithGoogleAction/VerifyTwoFactorChallengeAction:
 * either a full session (token + user) or, for a 2FA-enabled account, a
 * half-authenticated challenge the client must complete via
 * POST /auth/2fa/verify. A plain discriminated array shape here fights
 * PHPStan's type narrowing across three call sites, so this is a value
 * object instead.
 */
final readonly class LoginResult
{
    private function __construct(
        public bool $twoFactorRequired,
        public ?string $token = null,
        public ?User $user = null,
        public ?string $challengeToken = null,
    ) {}

    public static function success(string $token, User $user): self
    {
        return new self(twoFactorRequired: false, token: $token, user: $user);
    }

    public static function twoFactorChallenge(string $challengeToken): self
    {
        return new self(twoFactorRequired: true, challengeToken: $challengeToken);
    }
}
