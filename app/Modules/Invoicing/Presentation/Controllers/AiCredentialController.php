<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\UpsertAiCredentialAction;
use App\Modules\Invoicing\Application\Actions\VerifyAiCredentialAction;
use App\Modules\Invoicing\Application\DTOs\UpsertAiCredentialData;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Domain\Models\AiCredential;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Invoicing',
    description: 'Invoice, quote and supplier document management'
)]
class AiCredentialController extends Controller
{
    public function __construct(
        private readonly UpsertAiCredentialAction $upsertAction,
        private readonly VerifyAiCredentialAction $verifyAction,
    ) {}

    #[OA\Get(
        path: '/api/v1/ai-credentials',
        summary: 'List the account\'s BYOK credentials, one row per supported provider',
        security: [['sanctum' => []]],
        tags: ['Invoicing'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'One entry per supported provider — the key itself is never returned',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'provider', type: 'string', example: 'anthropic'),
                            new OA\Property(property: 'has_key', type: 'boolean'),
                            new OA\Property(property: 'verified_at', type: 'string', format: 'date-time', nullable: true),
                            new OA\Property(property: 'last_error', type: 'string', nullable: true),
                        ],
                        type: 'object',
                    )),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the account owner'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        $existing = AiCredential::query()
            ->where('user_id', $owner->id)
            ->get()
            ->keyBy(fn (AiCredential $credential): string => $credential->provider->value);

        /** @var Collection<int, array<string, mixed>> $data */
        $data = collect(AiProvider::cases())->map(function (AiProvider $provider) use ($existing): array {
            /** @var AiCredential|null $credential */
            $credential = $existing->get($provider->value);

            return [
                'provider' => $provider->value,
                'has_key' => $credential !== null,
                'verified_at' => $credential?->verified_at?->toISOString(),
                'last_error' => $credential?->last_error,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    #[OA\Put(
        path: '/api/v1/ai-credentials/{provider}',
        summary: 'Save (or replace) the account\'s BYOK key for a provider',
        security: [['sanctum' => []]],
        tags: ['Invoicing'],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'anthropic')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['api_key'], properties: [
                new OA\Property(property: 'api_key', type: 'string'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Saved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the account owner'),
            new OA\Response(response: 422, description: 'Validation error, or an unsupported provider'),
        ]
    )]
    public function upsert(Request $request, string $provider): JsonResponse
    {
        $owner = $this->owner($request);
        $aiProvider = $this->resolveProvider($provider);
        $data = UpsertAiCredentialData::validateAndCreate($request->all());

        $this->upsertAction->execute($owner, $aiProvider, $data);

        return response()->json(['message' => __('invoicing.ai_credential.saved')]);
    }

    #[OA\Delete(
        path: '/api/v1/ai-credentials/{provider}',
        summary: 'Remove the account\'s BYOK key for a provider',
        security: [['sanctum' => []]],
        tags: ['Invoicing'],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'anthropic')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted (a no-op if none was saved)'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the account owner'),
            new OA\Response(response: 422, description: 'Unsupported provider'),
        ]
    )]
    public function destroy(Request $request, string $provider): JsonResponse
    {
        $owner = $this->owner($request);
        $aiProvider = $this->resolveProvider($provider);

        AiCredential::query()
            ->where('user_id', $owner->id)
            ->where('provider', $aiProvider->value)
            ->delete();

        return response()->json(['message' => __('invoicing.ai_credential.deleted')]);
    }

    #[OA\Post(
        path: '/api/v1/ai-credentials/{provider}/test',
        summary: 'Validate the account\'s saved BYOK key for a provider',
        description: 'Makes a minimal, cheap call against the provider\'s API to confirm the saved key actually works.',
        security: [['sanctum' => []]],
        tags: ['Invoicing'],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'anthropic')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Result of the check',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'valid', type: 'boolean'),
                    new OA\Property(property: 'message', type: 'string'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the account owner'),
            new OA\Response(response: 422, description: 'No key saved, an unsupported provider, or the provider API could not be reached'),
        ]
    )]
    public function test(Request $request, string $provider): JsonResponse
    {
        $owner = $this->owner($request);
        $aiProvider = $this->resolveProvider($provider);

        $credential = AiCredential::query()
            ->where('user_id', $owner->id)
            ->where('provider', $aiProvider->value)
            ->first();

        if ($credential === null) {
            return response()->json(['message' => __('invoicing.ai_credential.key_invalid')], 422);
        }

        $valid = $this->verifyAction->execute($credential);

        return response()->json([
            'valid' => $valid,
            'message' => $valid ? __('invoicing.ai_credential.key_valid') : __('invoicing.ai_credential.key_invalid'),
        ]);
    }

    /**
     * BYOK credentials are account-wide (accountOwnerId()) — only the
     * account owner may view, rotate or test them, not a team member
     * merely benefiting from the owner's key.
     */
    private function owner(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->accountOwnerId() === $user->id, 403);

        return $user;
    }

    /**
     * @throws DomainException
     */
    private function resolveProvider(string $provider): AiProvider
    {
        return AiProvider::tryFrom($provider)
            ?? throw DomainException::validation(__('invoicing.ai_credential.unsupported_provider'));
    }
}
