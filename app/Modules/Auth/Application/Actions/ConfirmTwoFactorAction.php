<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\TwoFactorCodeData;
use App\Modules\Auth\Application\Services\TwoFactorService;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;

readonly class ConfirmTwoFactorAction
{
    public function __construct(
        private TwoFactorService $service,
    ) {}

    /**
     * @return list<string> plaintext recovery codes — shown exactly once
     *
     * @throws DomainException
     */
    public function execute(User $user, TwoFactorCodeData $data): array
    {
        if ($user->hasTwoFactorEnabled()) {
            throw DomainException::because(__('auth.two_factor_already_enabled'));
        }

        if ($user->two_factor_secret === null) {
            throw DomainException::because(__('auth.two_factor_not_started'));
        }

        if (! $this->service->verify($user->two_factor_secret, $data->code)) {
            throw DomainException::because(__('auth.invalid_2fa_code'));
        }

        $recoveryCodes = $this->service->generateRecoveryCodes();

        $user->update([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes['hashed'],
        ]);

        return $recoveryCodes['plain'];
    }
}
