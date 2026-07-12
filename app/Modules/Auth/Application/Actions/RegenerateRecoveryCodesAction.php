<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\TwoFactorCodeData;
use App\Modules\Auth\Application\Services\TwoFactorService;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;

readonly class RegenerateRecoveryCodesAction
{
    public function __construct(
        private TwoFactorService $service,
        private VerifyTwoFactorCodeAction $verifyCodeAction,
    ) {}

    /**
     * @return list<string> plaintext recovery codes — the old set is invalidated
     *
     * @throws DomainException
     */
    public function execute(User $user, TwoFactorCodeData $data): array
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw DomainException::because(__('auth.two_factor_not_enabled'));
        }

        if (! $this->verifyCodeAction->execute($user, $data->code)) {
            throw DomainException::because(__('auth.invalid_2fa_code'));
        }

        $recoveryCodes = $this->service->generateRecoveryCodes();

        $user->update(['two_factor_recovery_codes' => $recoveryCodes['hashed']]);

        return $recoveryCodes['plain'];
    }
}
