<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\Services\TwoFactorService;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Exceptions\DomainException;

readonly class EnableTwoFactorAction
{
    public function __construct(
        private TwoFactorService $service,
    ) {}

    /**
     * Generates and stores an unconfirmed secret. 2FA only becomes active
     * once confirm() verifies a code against it.
     *
     * @return array{secret: string, otpauth_uri: string, qr_svg: string}
     *
     * @throws DomainException
     */
    public function execute(User $user): array
    {
        if ($user->hasTwoFactorEnabled()) {
            throw DomainException::because(__('auth.two_factor_already_enabled'));
        }

        $secret = $this->service->generateSecret();

        $user->update(['two_factor_secret' => $secret]);

        $otpauthUri = $this->service->otpauthUri(
            issuer: (string) config('app.name'),
            email: $user->email,
            secret: $secret,
        );

        return [
            'secret' => $secret,
            'otpauth_uri' => $otpauthUri,
            'qr_svg' => $this->service->qrSvgDataUri($otpauthUri),
        ];
    }
}
