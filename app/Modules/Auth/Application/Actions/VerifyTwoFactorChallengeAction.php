<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\VerifyTwoFactorChallengeData;
use App\Modules\Auth\Application\Services\TwoFactorChallengeStore;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;

readonly class VerifyTwoFactorChallengeAction
{
    public function __construct(
        private TwoFactorChallengeStore $challengeStore,
        private VerifyTwoFactorCodeAction $verifyCodeAction,
    ) {}

    /**
     * @return array{token: string, user: User}
     *
     * @throws DomainException
     */
    public function execute(VerifyTwoFactorChallengeData $data): array
    {
        $user = $this->challengeStore->consume($data->challenge_token);

        if ($user === null) {
            throw DomainException::because(__('auth.challenge_expired'));
        }

        if (! $this->verifyCodeAction->execute($user, $data->code)) {
            throw DomainException::because(__('auth.invalid_2fa_code'));
        }

        $deviceName = $data->device_name ?? 'api-token';

        // Same device-name dedup as LoginAction/LoginWithGoogleAction.
        $user->tokens()->where('name', $deviceName)->delete();

        return [
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => $user,
        ];
    }
}
