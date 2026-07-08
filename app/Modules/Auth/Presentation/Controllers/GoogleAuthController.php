<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Controllers;

use App\Modules\Auth\Application\Actions\LoginWithGoogleAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use OpenApi\Attributes as OA;
use Throwable;

class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly LoginWithGoogleAction $loginWithGoogleAction,
    ) {}

    #[OA\Get(
        path: '/api/v1/auth/google/redirect',
        summary: 'Get Google OAuth redirect URL',
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Google OAuth redirect URL',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'url', type: 'string', example: 'https://accounts.google.com/oauth/authorize?...'),
                    ]
                )
            ),
        ]
    )]
    public function redirect(): JsonResponse
    {
        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');
        $url = $driver
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/auth/google/callback',
        summary: 'Handle Google OAuth callback',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'code', description: 'Google OAuth authorization code', type: 'string', example: '4/0AX4Xf...'),
                    new OA\Property(property: 'device_name', type: 'string', example: 'mobile-app'),
                ]
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Google login successful',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'token', type: 'string', example: '1|abc123...'),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid Google token',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Neplatný Google token.'),
                    ]
                )
            ),
        ]
    )]
    public function callback(Request $request): JsonResponse
    {
        try {
            /** @var GoogleProvider $driver */
            $driver = Socialite::driver('google');
            $googleUser = $driver
                ->stateless()
                ->user();
        } catch (\Exception) {
            return response()->json(['message' => __('auth.invalid_google_token')], 422);
        }

        $deviceName = $request->input('device_name', 'google-oauth');
        $token = $this->loginWithGoogleAction->execute($googleUser, $deviceName);

        return response()->json(['token' => $token]);
    }
}
