<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\DisableTwoFactorData;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Hash;

readonly class DisableTwoFactorAction
{
    public function __construct(
        private VerifyTwoFactorCodeAction $verifyCodeAction,
    ) {}

    /**
     * Disabling requires both the password and a valid code (TOTP or
     * recovery) — a stolen bearer token alone must not be enough to turn
     * 2FA off.
     *
     * @throws DomainException
     */
    public function execute(User $user, DisableTwoFactorData $data): void
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw DomainException::because(__('auth.two_factor_not_enabled'));
        }

        // Google-only accounts have no password to check against (same
        // exception DeleteAccountAction makes for account deletion) — the
        // code check below is still mandatory either way.
        if ($user->hasPassword()) {
            if ($data->password === null || ! Hash::check($data->password, (string) $user->password)) {
                throw DomainException::because(__('auth.invalid_password'));
            }
        }

        if ($data->code === null || ! $this->verifyCodeAction->execute($user, $data->code)) {
            throw DomainException::because(__('auth.invalid_2fa_code'));
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}
