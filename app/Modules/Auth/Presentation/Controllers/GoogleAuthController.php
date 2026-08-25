<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Controllers;

use App\Modules\Auth\Application\Actions\LoginWithGoogleAction;
use App\Modules\Auth\Presentation\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use OpenApi\Attributes as OA;
use Throwable;

class GoogleAuthController extends Controller
{
    private const string STATE_CACHE_PREFIX = 'google_oauth_state:';

    private const int STATE_TTL_MINUTES = 10;

    public function __construct(
        private readonly LoginWithGoogleAction $loginWithGoogleAction,
    ) {}

    #[OA\Get(
        path: '/api/v1/auth/google/redirect',
        summary: 'Get Google OAuth redirect URL',
        tags: ['Authentication'],
        parameters: [
            new OA\Parameter(
                name: 'platform',
                in: 'query',
                description: 'web (default) uses the SPA callback URL; mobile uses the app\'s custom-scheme in-app-browser callback',
                schema: new OA\Schema(type: 'string', enum: ['web', 'mobile'], default: 'web')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Google OAuth redirect URL',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'url', type: 'string', description: 'Carries a one-time state param the caller must send back to /callback', example: 'https://accounts.google.com/oauth/authorize?...&state=...'),
                    ]
                )
            ),
        ]
    )]
    public function redirect(Request $request): JsonResponse
    {
        $request->validate([
            'platform' => ['sometimes', 'string', 'in:web,mobile'],
        ]);

        $platform = $request->string('platform', 'web')->toString();

        // CSRF for the OAuth handshake: a one-time state nonce, cached
        // server-side and echoed back by Google in the redirect. Socialite is
        // stateless (SPA + API, no server session across the round trip), so it
        // won't manage state itself — the caller carries the state param from
        // Google's redirect into POST /callback, where it is validated and
        // consumed. Without it a callback the tenant never initiated (login
        // CSRF) would be accepted. The platform rides along on the same entry
        // so /callback knows which redirect_uri the token exchange must repeat
        // — OAuth2 requires it to match the one used here exactly.
        $state = Str::random(40);
        Cache::put(self::STATE_CACHE_PREFIX.$state, $platform, now()->addMinutes(self::STATE_TTL_MINUTES));

        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');

        if ($platform === 'mobile') {
            $driver->redirectUrl((string) config('services.google.mobile_redirect'));
        }

        $url = $driver
            ->stateless()
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    #[OA\Get(
        path: '/api/v1/auth/google/callback/mobile',
        summary: "Bridge Google's mobile callback to the app's custom scheme",
        description: 'Not called by any client directly — Google redirects the in-app browser here, '
            .'and this hands the code/state on to the app. A Web OAuth client only accepts http(s) '
            .'redirect URIs, so the app cannot be the redirect target itself.',
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 302, description: 'Redirect to the app (flok://auth/google/callback?code=&state=)'),
        ]
    )]
    public function mobileCallbackBridge(Request $request): RedirectResponse
    {
        // Whatever Google sent is echoed on unchanged — including `error`, so
        // a denied consent screen closes the in-app browser instead of hanging.
        // Nothing is validated here on purpose: the state is single-use and
        // POST /callback is the one place allowed to consume it. The target is
        // a fixed configured scheme, so this cannot be turned into an open
        // redirect by a crafted parameter.
        $params = $request->only(['code', 'state', 'error', 'error_description']);

        $target = (string) config('services.google.mobile_app_redirect');

        return redirect()->away($target.(str_contains($target, '?') ? '&' : '?').http_build_query($params));
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
                required: ['code', 'state'],
                properties: [
                    new OA\Property(property: 'code', description: 'Google OAuth authorization code', type: 'string', example: '4/0AX4Xf...'),
                    new OA\Property(property: 'state', description: 'The state param returned by Google, originally issued by /redirect', type: 'string'),
                    new OA\Property(property: 'device_name', type: 'string', example: 'mobile-app'),
                ]
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Google login successful, or a 2FA challenge when the account has two-factor enabled',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'two_factor_required', type: 'boolean', example: false),
                        new OA\Property(property: 'token', type: 'string', nullable: true, example: '1|abc123...', description: 'Present only when two_factor_required is false'),
                        new OA\Property(property: 'user', ref: '#/components/schemas/User', nullable: true),
                        new OA\Property(property: 'challenge_token', type: 'string', nullable: true, description: 'Present only when two_factor_required is true — pass it to POST /auth/2fa/verify'),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid Google token, or a missing/expired/unknown OAuth state',
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
        $request->validate([
            'state' => ['required', 'string'],
        ]);

        // Consume the one-time state issued by /redirect. pull() checks and
        // removes it in one step, so a replayed or forged callback (login CSRF)
        // whose state was never issued — or already used, or expired — is
        // rejected before the code is ever exchanged with Google.
        $platform = Cache::pull(self::STATE_CACHE_PREFIX.$request->string('state')->toString());

        if ($platform === null) {
            return response()->json(['message' => __('auth.invalid_oauth_state')], 422);
        }

        try {
            /** @var GoogleProvider $driver */
            $driver = Socialite::driver('google');

            // The token exchange's redirect_uri must exactly match the one
            // /redirect used for this same state, or Google rejects it with
            // redirect_uri_mismatch.
            if ($platform === 'mobile') {
                $driver->redirectUrl((string) config('services.google.mobile_redirect'));
            }

            $googleUser = $driver
                ->stateless()
                ->user();
        } catch (\Exception) {
            return response()->json(['message' => __('auth.invalid_google_token')], 422);
        }

        $deviceName = $request->input('device_name', 'google-oauth');
        $result = $this->loginWithGoogleAction->execute($googleUser, $deviceName, $request);

        if ($result->twoFactorRequired) {
            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => $result->challengeToken,
            ]);
        }

        return response()->json([
            'two_factor_required' => false,
            'token' => $result->token,
            'user' => UserResource::make($result->user),
        ]);
    }
}
