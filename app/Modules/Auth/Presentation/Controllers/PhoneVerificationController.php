<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Controllers;

use App\Modules\Auth\Application\Actions\ConfirmPhoneVerificationAction;
use App\Modules\Auth\Application\Actions\SendPhoneVerificationCodeAction;
use App\Modules\Auth\Application\DTOs\ConfirmPhoneVerificationData;
use App\Modules\Auth\Application\DTOs\SendPhoneVerificationData;
use App\Modules\Auth\Application\Results\PhoneVerificationSendResult;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Auth\Presentation\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;
use Throwable;

class PhoneVerificationController extends Controller
{
    public function __construct(
        private readonly SendPhoneVerificationCodeAction $sendAction,
        private readonly ConfirmPhoneVerificationAction $confirmAction,
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/phone/send-code',
        summary: 'Send an SMS verification code to the account phone number',
        description: 'Issues a one-time code. Returns 503 when phone verification is switched off or the SMS gateway is unreachable — never an error the caller can fix by changing the request.',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone'],
                properties: [
                    new OA\Property(property: 'phone', type: 'string', example: '+421900123456', description: 'E.164. Spaces, dashes and brackets are stripped before validation.'),
                ],
            ),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Code sent',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])
            ),
            new OA\Response(response: 422, description: 'Number already verified by another account, or resent inside the cooldown'),
            new OA\Response(response: 503, description: 'Verification disabled, or the SMS gateway is unavailable'),
        ],
    )]
    public function sendCode(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = SendPhoneVerificationData::validateAndCreate($request->all());

        return match ($this->sendAction->execute($user, $data)) {
            PhoneVerificationSendResult::Sent => response()->json([
                'message' => __('auth.phone_code_sent'),
            ]),
            PhoneVerificationSendResult::Disabled => response()->json([
                'message' => __('auth.phone_verification_disabled'),
            ], 503),
            PhoneVerificationSendResult::Unavailable => response()->json([
                'message' => __('auth.phone_verification_unavailable'),
            ], 503),
        };
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/auth/phone/verify',
        summary: 'Confirm the SMS verification code',
        description: 'Marks the number proved. On the SaaS edition this is what converts a pending trial entitlement into an active trial.',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: '123456', description: 'The six digits from the SMS'),
                ],
            ),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Number verified',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ])
            ),
            new OA\Response(response: 422, description: 'Wrong, expired or exhausted code, or the number was verified elsewhere first'),
        ],
    )]
    public function verify(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = ConfirmPhoneVerificationData::validateAndCreate($request->all());

        $user = $this->confirmAction->execute($user, $data);

        return response()->json([
            'message' => __('auth.phone_verified'),
            'data' => new UserResource($user),
        ]);
    }
}
