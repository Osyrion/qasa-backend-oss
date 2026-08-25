<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Controllers;

use App\Modules\Shared\Application\Actions\SubscribeToWaitlistAction;
use App\Modules\Shared\Application\DTOs\WaitlistSignupData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

class WaitlistController extends Controller
{
    public function __construct(
        private readonly SubscribeToWaitlistAction $action,
    ) {}

    #[OA\Post(
        path: '/api/v1/public/waitlist',
        summary: 'Join the beta waitlist',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'locale'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'locale', type: 'string', enum: ['cs', 'sk', 'en']),
                    new OA\Property(property: 'page_variant', type: 'string', maxLength: 50, nullable: true, example: 'cz-invoicing-vida'),
                    new OA\Property(property: 'honeypot', type: 'string', nullable: true, description: 'Anti-spam trap field — must stay empty'),
                    new OA\Property(property: 'turnstile_token', type: 'string', nullable: true, description: 'Cloudflare Turnstile response token — required only when services.turnstile.enabled is on'),
                ]
            )
        ),
        tags: ['Waitlist'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Signup accepted (idempotent — also returned for an address already on the list)',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])
            ),
            new OA\Response(response: 422, description: 'Validation failed, or captcha verification failed'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate(WaitlistSignupData::rules());
        $data = WaitlistSignupData::fromRequest($request);
        $this->action->execute($data, $request->ip());

        return response()->json(['message' => __('shared.waitlist.subscribed')], 201);
    }
}
