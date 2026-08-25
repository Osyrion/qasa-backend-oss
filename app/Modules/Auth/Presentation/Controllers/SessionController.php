<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Auth\Presentation\Resources\SessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

/**
 * "My devices" — the login tokens created by LoginAction and every other
 * sign-in path (Google OAuth, 2FA challenge, invitation accept), tagged
 * `type = 'session'` by User::createSessionToken(). Deliberately separate
 * from PersonalAccessTokenController, which only ever sees `type = 'api'`
 * integration tokens — the two endpoints used to share one unfiltered list,
 * which meant an API integration token and a browser login were
 * indistinguishable to the caller.
 */
#[OA\Tag(name: 'Sessions', description: 'Login tokens ("my devices") — separate from API integration tokens')]
class SessionController extends Controller
{
    #[OA\Get(
        path: '/api/v1/auth/sessions',
        summary: 'List the caller\'s active login sessions',
        security: [['sanctum' => []]],
        tags: ['Sessions'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sessions',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Session')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = $this->currentTokenId($user);

        $sessions = $user->tokens()
            ->where('type', 'session')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalAccessToken $token): array => (new SessionResource($token, $currentId))->toArray($request))
            ->values();

        return response()->json(['data' => $sessions]);
    }

    #[OA\Delete(
        path: '/api/v1/auth/sessions/{id}',
        summary: 'Revoke one login session',
        description: 'Refuses to revoke the session the request is authenticated with — log in elsewhere and revoke it from there, or use "log out everywhere else".',
        security: [['sanctum' => []]],
        tags: ['Sessions'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Revoked'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Attempted to revoke the current session'),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = $this->currentTokenId($user);

        if ($currentId !== null && (string) $currentId === $id) {
            return response()->json(['message' => __('auth.cannot_revoke_current_session')], 422);
        }

        $deleted = $user->tokens()->where('id', $id)->where('type', 'session')->delete();

        abort_if($deleted === 0, 404);

        return response()->json(null, 204);
    }

    #[OA\Delete(
        path: '/api/v1/auth/sessions',
        summary: 'Log out every other session',
        description: 'Revokes every login session except the one the request is authenticated with.',
        security: [['sanctum' => []]],
        tags: ['Sessions'],
        responses: [
            new OA\Response(response: 200, description: 'How many were revoked'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function destroyOthers(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = $this->currentTokenId($user);

        $query = $user->tokens()->where('type', 'session');

        if ($currentId !== null) {
            $query->where('id', '!=', $currentId);
        }

        $revoked = $query->delete();

        return response()->json(['revoked' => $revoked]);
    }

    #[OA\Put(
        path: '/api/v1/auth/push-token',
        summary: "Register or clear this device's push notification token",
        description: 'Attached to the session token the request is authenticated with — revoking that '
            .'session (or logging out) stops its pushes too, no separate unregister call needed.',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'push_token', type: 'string', nullable: true, description: 'Null clears the registration'),
                    new OA\Property(property: 'push_platform', type: 'string', enum: ['ios', 'android'], nullable: true),
                ]
            )
        ),
        tags: ['Sessions'],
        responses: [
            new OA\Response(response: 204, description: 'Updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Not authenticated via a login session (e.g. an API integration token)'),
        ]
    )]
    public function updatePushToken(Request $request): JsonResponse
    {
        $request->validate([
            'push_token' => ['nullable', 'string', 'max:255'],
            'push_platform' => ['nullable', 'string', 'in:ios,android', 'required_with:push_token'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        // @phpstan-ignore instanceof.alwaysTrue
        if (! $token instanceof PersonalAccessToken || $token->type !== 'session') {
            return response()->json(['message' => __('auth.push_token_requires_session')], 422);
        }

        $pushToken = $request->input('push_token');

        // One device, one registration. Reinstalling the app or signing in
        // again mints a fresh session while the previous one may still be
        // alive and still carrying the same Expo token — leaving it there
        // would make the sender deliver every notification to that handset
        // twice, once per session row.
        if (is_string($pushToken) && $pushToken !== '') {
            $user->tokens()
                ->where('push_token', $pushToken)
                ->whereKeyNot($token->getKey())
                ->update(['push_token' => null, 'push_platform' => null]);
        }

        $token->forceFill([
            'push_token' => $pushToken,
            'push_platform' => $request->input('push_platform'),
        ])->save();

        return response()->json(null, 204);
    }

    /**
     * currentAccessToken() is typed as PersonalAccessToken but, at runtime,
     * is a TransientToken whenever the request authenticated some other way
     * (actingAs() in tests, any guard resolved ahead of the bearer check) —
     * Sanctum's own Guard does exactly this. TransientToken has no
     * persisted id, so treat it the same as "unknown" rather than let
     * getKey() run on it.
     */
    private function currentTokenId(User $user): int|string|null
    {
        $token = $user->currentAccessToken();

        // PersonalAccessToken is the PHPDoc'd type; TransientToken is the real one Sanctum substitutes.
        // @phpstan-ignore instanceof.alwaysTrue
        return $token instanceof PersonalAccessToken ? $token->getKey() : null;
    }
}
