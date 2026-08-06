<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Support\AccountLookup;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

class EmailVerificationController extends Controller
{
    #[OA\Get(
        path: '/api/v1/auth/email/verify/{id}/{hash}',
        summary: 'Verify email address (signed link from the verification email)',
        tags: ['Authentication'],
        parameters: [
            new OA\Parameter(name: 'id', description: 'User ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'hash', description: 'Verification hash', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Email verified',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Email bol úspešne overený.'),
                ])
            ),
            new OA\Response(response: 403, description: 'Invalid or expired verification link'),
        ]
    )]
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        // Clicking the link is an unauthenticated request, so nothing has
        // bound the connection — and `users` is tenant-scoped (phase 7,
        // docs/plans/POSTGRES_RLS_PLAN.md), which makes the row below
        // invisible and turns a valid link into a 404. The signed URL is what
        // proves this id was issued by us, so binding from it is safe; the
        // hash below still has to match before anything is marked verified.
        AccountLookup::bindById($id);

        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['message' => __('auth.invalid_verification_link')], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return response()->json(['message' => __('auth.email_verified')]);
    }

    #[OA\Post(
        path: '/api/v1/auth/email/verification-notification',
        summary: 'Resend the email verification link',
        security: [['sanctum' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Verification email sent (or already verified)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Overovací email bol odoslaný.'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => __('auth.email_already_verified')]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => __('auth.verification_email_sent')]);
    }
}
