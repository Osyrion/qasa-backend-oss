<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Controllers;

use App\Modules\Shared\Application\Services\AiTransparencyRegister;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesAiPreferences;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

/**
 * The AI Act art. 50 notice, served rather than only written down: which AI
 * capabilities are live for this account, what each one sends to which model
 * provider, and how to switch it off. docs/legal/AI_ACT.md is the same
 * register in prose for the file; this is the copy the UI shows a user
 * before they turn AI on.
 *
 * Reads the caller's own account state only (no foreign id, no policy) —
 * see ownAccountRouteAllowlist() in RoutePermissionCoverageTest.
 */
#[OA\Tag(name: 'AI', description: 'AI transparency (Regulation (EU) 2024/1689)')]
class AiTransparencyController extends Controller
{
    public function __construct(
        private readonly AiTransparencyRegister $register,
    ) {}

    #[OA\Get(
        path: '/api/v1/ai/transparency',
        summary: 'AI capabilities active for the caller\'s account, what they send where, and how to turn them off',
        description: 'Transparency notice under art. 50 of Regulation (EU) 2024/1689. Every capability listed produces a suggestion or a draft a human confirms — nothing here decides anything on its own, so no automated decision-making in the sense of GDPR art. 22 takes place.',
        security: [['sanctum' => []]],
        tags: ['AI'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transparency register for this account',
                content: new OA\JsonContent(properties: [
                    new OA\Property(
                        property: 'data',
                        properties: [
                            new OA\Property(property: 'ai_enabled', type: 'boolean', description: 'At least one AI capability can actually run for this account right now'),
                            new OA\Property(property: 'globally_disabled', type: 'boolean', description: 'QASA_AI_DISABLED kill switch — no outbound model call happens on this deployment at all'),
                            new OA\Property(property: 'provider', type: 'string', nullable: true, example: 'anthropic'),
                            new OA\Property(property: 'model', type: 'string', nullable: true, example: 'claude-haiku-4-5'),
                            new OA\Property(property: 'own_api_key', type: 'boolean', description: 'True when the account uses its own (BYOK) key, so the model provider is the account\'s own contractual counterparty'),
                            new OA\Property(property: 'notice', type: 'string'),
                            new OA\Property(property: 'human_oversight', type: 'string'),
                            new OA\Property(property: 'automated_decision_making', type: 'boolean', example: false),
                            new OA\Property(property: 'training', type: 'string'),
                            new OA\Property(property: 'complaints', type: 'string'),
                            new OA\Property(
                                property: 'features',
                                type: 'array',
                                items: new OA\Items(properties: [
                                    new OA\Property(property: 'key', type: 'string', example: 'invoice_extraction'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'purpose', type: 'string'),
                                    new OA\Property(property: 'data_sent', type: 'string'),
                                    new OA\Property(property: 'output', type: 'string', enum: ['suggestion', 'generated_text']),
                                    new OA\Property(property: 'opt_out', type: 'string'),
                                    new OA\Property(property: 'enabled', type: 'boolean'),
                                    new OA\Property(property: 'human_review_required', type: 'boolean', example: true),
                                    new OA\Property(property: 'automated_decision_making', type: 'boolean', example: false),
                                ], type: 'object'),
                            ),
                        ],
                        type: 'object'
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var Account&ProvidesAiPreferences&ProvidesPlanEntitlements $user */
        $user = $request->user();

        return response()->json(['data' => $this->register->forOwner($user)]);
    }
}
