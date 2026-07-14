<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\Services\TwoFactorService;
use App\Modules\Auth\Domain\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Verifies a user-submitted 2FA code against either the TOTP secret or the
 * hashed recovery codes. Shared by disable, recovery-code regeneration, and
 * the login challenge — a matched recovery code is consumed (removed) here
 * so callers don't have to duplicate that bookkeeping.
 */
readonly class VerifyTwoFactorCodeAction
{
    public function __construct(
        private TwoFactorService $service,
    ) {}

    public function execute(User $user, string $code): bool
    {
        if ($user->two_factor_secret !== null && $this->service->verify($user->two_factor_secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashedCodes = $user->two_factor_recovery_codes ?? [];

        foreach ($hashedCodes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashedCodes[$index]);
                $user->update(['two_factor_recovery_codes' => array_values($hashedCodes)]);

                return true;
            }
        }

        return false;
    }
}
