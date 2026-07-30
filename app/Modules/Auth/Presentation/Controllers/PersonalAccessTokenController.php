<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Controllers;

use App\Modules\Auth\Application\DTOs\CreateTokenData;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Auth\Presentation\Resources\PersonalAccessTokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Tokens',
    description: 'Scoped personal access tokens for third-party integrations'
)]
class PersonalAccessTokenController extends Controller
{
    #[OA\Get(
        path: '/api/v1/auth/tokens',
        summary: 'List personal access tokens',
        security: [['sanctum' => []]],
        tags: ['Tokens'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tokens',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/PersonalAccessToken'))
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return PersonalAccessTokenResource::collection(
            $user->tokens()->orderByDesc('created_at')->get()
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/tokens',
        summary: 'Create a scoped personal access token',
        description: 'The plaintext token is returned only in this response and cannot be retrieved again.',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'abilities'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'zapier-integration', maxLength: 100),
                    new OA\Property(property: 'abilities', type: 'array', items: new OA\Items(type: 'string', example: 'invoices.view')),
                ]
            )
        ),
        tags: ['Tokens'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'token', type: 'string', example: '1|abc123...'),
                    new OA\Property(property: 'access_token', ref: '#/components/schemas/PersonalAccessToken'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate(CreateTokenData::rules());
        $data = CreateTokenData::fromRequest($request);

        /** @var User $user */
        $user = $request->user();

        $token = $user->createToken($data->name, $data->abilities);

        return response()->json([
            'token' => $token->plainTextToken,
            'access_token' => PersonalAccessTokenResource::make($token->accessToken),
        ], 201);
    }

    #[OA\Delete(
        path: '/api/v1/auth/tokens/{id}',
        summary: 'Revoke a personal access token',
        security: [['sanctum' => []]],
        tags: ['Tokens'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deleted = $user->tokens()->where('id', $id)->delete();

        abort_if($deleted === 0, 404);

        return response()->json(null, 204);
    }
}
