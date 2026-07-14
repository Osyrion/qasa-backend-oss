<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Controllers;

use App\Modules\Auth\Application\Actions\ConfirmTwoFactorAction;
use App\Modules\Auth\Application\Actions\DisableTwoFactorAction;
use App\Modules\Auth\Application\Actions\EnableTwoFactorAction;
use App\Modules\Auth\Application\Actions\RegenerateRecoveryCodesAction;
use App\Modules\Auth\Application\Actions\VerifyTwoFactorChallengeAction;
use App\Modules\Auth\Application\DTOs\DisableTwoFactorData;
use App\Modules\Auth\Application\DTOs\TwoFactorCodeData;
use App\Modules\Auth\Application\DTOs\VerifyTwoFactorChallengeData;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Auth\Presentation\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'TwoFactor',
    description: 'TOTP-based two-factor authentication'
)]
class TwoFactorController extends Controller
{
    #[OA\Post(
        path: '/api/v1/auth/2fa/enable',
        summary: 'Start 2FA setup — generates an unconfirmed secret',
        security: [['sanctum' => []]],
        tags: ['TwoFactor'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Secret and provisioning QR code',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'secret', type: 'string'),
                    new OA\Property(property: 'otpauth_uri', type: 'string'),
                    new OA\Property(property: 'qr_svg', type: 'string', description: 'data: URI, SVG'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Already enabled'),
        ]
    )]
    public function enable(Request $request, EnableTwoFactorAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($action->execute($user));
    }

    #[OA\Post(
        path: '/api/v1/auth/2fa/confirm',
        summary: 'Confirm 2FA setup with a TOTP code — activates it and returns recovery codes',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['code'], properties: [
                new OA\Property(property: 'code', type: 'string', example: '123456'),
            ])
        ),
        tags: ['TwoFactor'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Recovery codes — shown only once',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'recovery_codes', type: 'array', items: new OA\Items(type: 'string')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid code or not started'),
        ]
    )]
    public function confirm(Request $request, ConfirmTwoFactorAction $action): JsonResponse
    {
        $request->validate(TwoFactorCodeData::rules());

        /** @var User $user */
        $user = $request->user();

        $recoveryCodes = $action->execute($user, TwoFactorCodeData::fromRequest($request));

        return response()->json(['recovery_codes' => $recoveryCodes]);
    }

    #[OA\Delete(
        path: '/api/v1/auth/2fa',
        summary: 'Disable 2FA — requires password and a valid TOTP or recovery code',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['code'], properties: [
                new OA\Property(property: 'password', type: 'string', nullable: true, description: 'Required unless the account has no password (Google-only)'),
                new OA\Property(property: 'code', type: 'string', example: '123456'),
            ])
        ),
        tags: ['TwoFactor'],
        responses: [
            new OA\Response(response: 204, description: 'Disabled'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid password or code'),
        ]
    )]
    public function disable(Request $request, DisableTwoFactorAction $action): JsonResponse
    {
        $request->validate(DisableTwoFactorData::rules());

        /** @var User $user */
        $user = $request->user();

        $action->execute($user, DisableTwoFactorData::fromRequest($request));

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/auth/2fa/recovery-codes',
        summary: 'Regenerate recovery codes — invalidates the previous set',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['code'], properties: [
                new OA\Property(property: 'code', type: 'string', example: '123456'),
            ])
        ),
        tags: ['TwoFactor'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'New recovery codes — shown only once',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'recovery_codes', type: 'array', items: new OA\Items(type: 'string')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid code or 2FA not enabled'),
        ]
    )]
    public function recoveryCodes(Request $request, RegenerateRecoveryCodesAction $action): JsonResponse
    {
        $request->validate(TwoFactorCodeData::rules());

        /** @var User $user */
        $user = $request->user();

        $recoveryCodes = $action->execute($user, TwoFactorCodeData::fromRequest($request));

        return response()->json(['recovery_codes' => $recoveryCodes]);
    }

    #[OA\Post(
        path: '/api/v1/auth/2fa/verify',
        summary: 'Complete login by verifying a 2FA code against a login challenge',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['challenge_token', 'code'], properties: [
                new OA\Property(property: 'challenge_token', type: 'string'),
                new OA\Property(property: 'code', type: 'string', example: '123456'),
                new OA\Property(property: 'device_name', type: 'string', example: 'mobile-app'),
            ])
        ),
        tags: ['TwoFactor'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login successful',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'token', type: 'string', example: '1|abc123...'),
                    new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                ])
            ),
            new OA\Response(response: 422, description: 'Invalid/expired challenge or invalid code'),
        ]
    )]
    public function verify(Request $request, VerifyTwoFactorChallengeAction $action): JsonResponse
    {
        $request->validate(VerifyTwoFactorChallengeData::rules());

        $result = $action->execute(VerifyTwoFactorChallengeData::fromRequest($request));

        return response()->json([
            'token' => $result['token'],
            'user' => UserResource::make($result['user']),
        ]);
    }
}
