<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Thin wrapper around pragmarx/google2fa (secret generation + TOTP
 * verification) and chillerlan/php-qrcode (provisioning QR), mirroring
 * PaymentQrService's SVG data-URI style for the invoice payment QR.
 */
class TwoFactorService
{
    /**
     * A TOTP code is valid for one window either side of the current one
     * (±30s) to tolerate clock drift between server and authenticator app.
     */
    private const WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(
        private readonly Google2FA $engine,
    ) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function otpauthUri(string $issuer, string $email, string $secret): string
    {
        return $this->engine->getQRCodeUrl($issuer, $email, $secret);
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code, self::WINDOW) === true;
    }

    public function qrSvgDataUri(string $otpauthUri): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'svgAddXmlHeader' => true,
        ]);

        return (string) (new QRCode($options))->render($otpauthUri);
    }

    /**
     * @return array{plain: list<string>, hashed: list<string>}
     */
    public function generateRecoveryCodes(): array
    {
        $plain = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $plain[] = Str::lower(Str::random(4)).'-'.Str::lower(Str::random(4));
        }

        return [
            'plain' => $plain,
            'hashed' => array_map(static fn (string $code): string => Hash::make($code), $plain),
        ];
    }
}
