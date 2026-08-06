<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Contracts;

/**
 * TOTP secrets, provisioning QR and recovery codes.
 *
 * Extracted so another module can do two-factor authentication without
 * reaching into Auth's concrete Application\Services — the boundary
 * ModuleBoundariesTest enforces. The admin guard is the second consumer;
 * sharing the implementation is the point, since a back office running
 * different TOTP parameters from the tenant side would be a bug nobody
 * would notice until someone's authenticator disagreed.
 */
interface TwoFactorServiceInterface
{
    public function generateSecret(): string;

    public function otpauthUri(string $issuer, string $email, string $secret): string;

    public function verify(string $secret, string $code): bool;

    public function qrSvgDataUri(string $otpauthUri): string;

    /**
     * @return array{plain: list<string>, hashed: list<string>}
     */
    public function generateRecoveryCodes(): array;
}
