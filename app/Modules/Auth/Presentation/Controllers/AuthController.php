<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Controllers;

use App\Modules\Auth\Application\Actions\AcceptTermsAction;
use App\Modules\Auth\Application\Actions\DeleteAccountAction;
use App\Modules\Auth\Application\Actions\LoginAction;
use App\Modules\Auth\Application\Actions\RegisterUserAction;
use App\Modules\Auth\Application\Actions\UpdateProfileAction;
use App\Modules\Auth\Application\DTOs\DeleteAccountData;
use App\Modules\Auth\Application\DTOs\LoginData;
use App\Modules\Auth\Application\DTOs\RegisterUserData;
use App\Modules\Auth\Application\DTOs\UpdateProfileData;
use App\Modules\Auth\Application\Services\AccountExportService;
use App\Modules\Auth\Domain\Events\UserLoggedOut;
use App\Modules\Auth\Domain\Exceptions\CaptchaRequiredException;
use App\Modules\Auth\Domain\Exceptions\TooManyLoginAttemptsException;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Auth\Presentation\Resources\UserResource;
use App\Modules\Shared\Application\Services\WaitlistInvitations;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly RegisterUserAction $registerAction,
        private readonly LoginAction $loginAction,
        private readonly UpdateProfileAction $updateProfileAction,
        private readonly AccountExportService $exportService,
        private readonly DeleteAccountAction $deleteAccountAction,
        private readonly AcceptTermsAction $acceptTermsAction,
        private readonly WaitlistInvitations $waitlistInvitations,
    ) {}

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/auth/register',
        summary: 'Register a new user',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'surname', 'email', 'password', 'accepted_terms'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Ján', maxLength: 100),
                    new OA\Property(property: 'surname', type: 'string', example: 'Novák', maxLength: 100),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jan@example.com', maxLength: 255),
                    new OA\Property(property: 'password', type: 'string', example: 'password123', maxLength: 255, minLength: 8),
                    new OA\Property(property: 'accepted_terms', type: 'boolean', example: true, description: 'Must be true — records terms_accepted_at against the current gdpr.terms_version'),
                    new OA\Property(property: 'title', type: 'string', example: 'Ing.', nullable: true, maxLength: 100),
                    new OA\Property(property: 'default_currency', type: 'string', example: 'EUR', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'locale', type: 'string', example: 'sk'),
                    new OA\Property(property: 'device_name', type: 'string', example: 'mobile-app'),
                    new OA\Property(property: 'invitation_token', type: 'string', nullable: true, description: 'Beta waitlist invitation. Required while public registration is closed, and must match the address the invitation was sent to.'),
                ]
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'User registered successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    new OA\Property(property: 'token', type: 'string', example: '1|abc123...'),
                ])
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid.'),
                    new OA\Property(property: 'errors', type: 'object'),
                ])
            ),
        ]
    )]
    public function register(Request $request): JsonResponse
    {
        // Public registration is a SaaS feature; the OSS edition creates
        // users via `php artisan qasa:user`. During the closed beta it is off
        // here too, and an invitation off the waitlist is the way in.
        $invitation = $this->waitlistInvitations->findUsable(
            $request->filled('invitation_token') ? $request->string('invitation_token')->toString() : null
        );

        abort_unless((bool) config('qasa.features.registration') || $invitation !== null, 404);

        $request->validate(RegisterUserData::rules(), [
            'accepted_terms.accepted' => __('auth.terms_must_be_accepted'),
        ]);

        $data = RegisterUserData::fromRequest($request);

        // An invitation is for the address it was sent to. Without this the
        // token is a skeleton key: forward the mail to anyone and the closed
        // beta is open, and the funnel can no longer say which signup became
        // which account.
        if ($invitation !== null && Str::lower($data->email) !== Str::lower($invitation->email)) {
            throw DomainException::because(__('auth.invitation_email_mismatch'));
        }

        $user = $this->registerAction->execute($data, $request->ip());

        if ($invitation !== null) {
            $this->waitlistInvitations->markRegistered($invitation);
        }

        $token = $user->createSessionToken(
            $request->input('device_name', 'api-token'),
            $request->ip(),
            $request->userAgent(),
        )->plainTextToken;

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $token,
        ], 201);
    }

    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'Login user',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jan@example.com', maxLength: 255),
                    new OA\Property(property: 'password', type: 'string', example: 'password123', maxLength: 255),
                    new OA\Property(property: 'remember', type: 'boolean', example: false),
                    new OA\Property(property: 'device_name', type: 'string', example: 'mobile-app'),
                    new OA\Property(property: 'turnstile_token', type: 'string', nullable: true, description: 'Cloudflare Turnstile token. Only needed after a 422 carrying captcha_required — an account under attack must answer a challenge before its credentials are looked at.'),
                ]
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login successful, or a 2FA challenge when the account has two-factor enabled',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'two_factor_required', type: 'boolean', example: false),
                    new OA\Property(property: 'token', type: 'string', nullable: true, example: '1|abc123...', description: 'Present only when two_factor_required is false'),
                    new OA\Property(property: 'user', ref: '#/components/schemas/User', nullable: true),
                    new OA\Property(property: 'challenge_token', type: 'string', nullable: true, description: 'Present only when two_factor_required is true — pass it to POST /auth/2fa/verify'),
                ])
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid credentials, or a captcha challenge when the account has taken enough failures to trip the gate',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Nesprávny email alebo heslo.'),
                    new OA\Property(property: 'captcha_required', type: 'boolean', nullable: true, description: 'Present and true when the caller must resubmit with turnstile_token. Credentials were not checked.'),
                ])
            ),
            new OA\Response(
                response: 429,
                description: 'Too many failed attempts from this address for this account — progressive backoff. Retry-After carries the wait in seconds.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Príliš veľa neúspešných pokusov o prihlásenie. Skúste to znova o 2 min.'),
                ])
            ),
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        $request->validate(LoginData::rules());

        try {
            $data = LoginData::fromRequest($request);
            $result = $this->loginAction->execute($data);

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
        } catch (CaptchaRequiredException $e) {
            // The module's usual 422, plus the one flag the client needs to
            // tell "wrong password" from "prove you are a person" and render
            // the widget instead of an error.
            return response()->json([
                'message' => $e->getMessage(),
                'captcha_required' => true,
            ], 422);
        } catch (TooManyLoginAttemptsException $e) {
            // Caught ahead of its parent: a backoff is a wait, not a wrong
            // password, and the client needs both the status and the header
            // to tell the difference and say so.
            return response()
                ->json(['message' => $e->getMessage()], 429)
                ->header('Retry-After', (string) $e->retryAfterSeconds);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/auth/logout',
        summary: 'Logout user',
        security: [['sanctum' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logout successful',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Odhlásenie úspešné.'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        // A session-authenticated (stateful SPA) request has no real token
        // to revoke — currentAccessToken() is a TransientToken there, which
        // has no delete() method at all.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        event(new UserLoggedOut($user));

        return response()->json(['message' => __('auth.logout_success')]);
    }

    #[OA\Get(
        path: '/api/v1/auth/me',
        summary: 'Get current user',
        security: [['sanctum' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Current user data',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')],
                    type: 'object',
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        return UserResource::make($request->user())->response();
    }

    /**
     * @throws Throwable
     */
    #[OA\Put(
        path: '/api/v1/auth/profile',
        summary: 'Update user profile',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'title', type: 'string', example: 'Ing.', nullable: true, maxLength: 100),
                new OA\Property(property: 'name', type: 'string', example: 'Ján', nullable: true, maxLength: 100),
                new OA\Property(property: 'surname', type: 'string', example: 'Novák', nullable: true, maxLength: 100),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jan@example.com', nullable: true, maxLength: 255),
                new OA\Property(property: 'phone', type: 'string', example: '+421 900 123 456', nullable: true, maxLength: 30),
                new OA\Property(property: 'password', type: 'string', example: 'newpassword123', nullable: true, maxLength: 255, minLength: 8),
                new OA\Property(property: 'current_password', description: 'Required when changing `email` or `password` on an account that has one (OAuth-only accounts are exempt) — the request is rejected with 422 otherwise.', type: 'string', nullable: true),
                new OA\Property(property: 'ico', type: 'string', example: '12345678', nullable: true, maxLength: 20),
                new OA\Property(property: 'dic', type: 'string', example: '1234567890', nullable: true, maxLength: 20),
                new OA\Property(property: 'is_vat_payer', type: 'boolean', example: true, nullable: true),
                new OA\Property(property: 'vat_status', type: 'string', example: 'payer', enum: ['non_payer', 'identified', 'payer']),
                new OA\Property(property: 'tax_flat_rate', type: 'integer', example: 20, nullable: true),
                new OA\Property(property: 'default_currency', type: 'string', example: 'EUR', nullable: true, enum: ['CZK', 'EUR', 'USD']),
                new OA\Property(property: 'invoice_prefix', type: 'string', example: 'FA', nullable: true, maxLength: 10),
                new OA\Property(property: 'invoice_number_mask', type: 'string', example: '{YYYY}{NNNN}', nullable: true, maxLength: 40),
                new OA\Property(property: 'invoice_number_start', type: 'integer', example: 1, nullable: true),
                new OA\Property(property: 'quote_number_mask', type: 'string', example: '{YYYY}{NNNN}', nullable: true, maxLength: 40),
                new OA\Property(property: 'quote_number_start', type: 'integer', example: 1, nullable: true, maximum: 99999999, minimum: 1),
                new OA\Property(property: 'locale', type: 'string', example: 'sk', nullable: true, maxLength: 5),
                new OA\Property(property: 'company_name', type: 'string', example: 'Ján Novák — JN Services', nullable: true, maxLength: 255),
                new OA\Property(property: 'address', type: 'string', example: 'Hlavná 1', nullable: true),
                new OA\Property(property: 'city', type: 'string', example: 'Bratislava', nullable: true),
                new OA\Property(property: 'postal_code', type: 'string', example: '811 01', nullable: true, maxLength: 10),
                new OA\Property(property: 'vat_id', type: 'string', example: 'SK1234567890', nullable: true, maxLength: 20),
                new OA\Property(property: 'website', type: 'string', example: 'https://example.com', nullable: true, maxLength: 150),
                new OA\Property(property: 'invoice_footer_text', type: 'string', nullable: true, maxLength: 1000),
                new OA\Property(property: 'clockify_api_key', type: 'string', nullable: true, maxLength: 100),
                new OA\Property(property: 'clockify_workspace_id', type: 'string', nullable: true, maxLength: 50),
                // The AI Act art. 50 opt-out: the transparency notice promises
                // the account can switch extraction off, so the switch has to
                // be reachable through the documented API, not just accepted
                // by UpdateProfileData (the generated clients only know what
                // this body declares).
                new OA\Property(property: 'ai_extraction_enabled', type: 'boolean', nullable: true),
                new OA\Property(property: 'auto_remind_enabled', type: 'boolean', nullable: true),
                new OA\Property(property: 'auto_remind_after_days', type: 'integer', example: 3, nullable: true),
                new OA\Property(property: 'auto_remind_max_count', type: 'integer', example: 3, nullable: true),
                new OA\Property(property: 'overdue_digest_enabled', type: 'boolean', nullable: true),
                new OA\Property(property: 'vat_filing_frequency', type: 'string', example: 'monthly', nullable: true, enum: ['monthly', 'quarterly']),
                new OA\Property(property: 'tax_filing_reminder_enabled', type: 'boolean', nullable: true),
            ])
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profile updated successfully',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')],
                    type: 'object',
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(UpdateProfileData::rules($user));

        $data = UpdateProfileData::fromRequest($request);
        $user = $this->updateProfileAction->execute($user, $data);

        return UserResource::make($user)->response();
    }

    #[OA\Post(
        path: '/api/v1/auth/profile/logo',
        summary: 'Upload supplier logo printed on invoices',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['logo'],
                    properties: [
                        new OA\Property(property: 'logo', type: 'string', format: 'binary'),
                    ]
                )
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logo uploaded',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')],
                    type: 'object',
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function uploadLogo(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        if ($user->logo_path !== null) {
            Storage::disk('public')->delete($user->logo_path);
        }

        $path = $request->file('logo')->store('logos/'.$user->id, 'public');

        $user->update(['logo_path' => $path]);

        return UserResource::make($user->fresh())->response();
    }

    #[OA\Get(
        path: '/api/v1/profile/export',
        summary: 'Export personal data as JSON (GDPR data portability)',
        description: 'The account owner receives the whole account. A team member receives '
            .'their own personal data only: profile, the activity they performed, and the '
            .'notifications addressed to them — the account\'s business records belong to '
            .'the owner as their controller.',
        security: [['sanctum' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'JSON export'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function exportData(Request $request): StreamedResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // A member is a data subject too — Art. 20 is about the person, not
        // about who owns the account. In the OSS edition accountOwnerId() is
        // always the user's own id, so this branch simply never fires there.
        $data = $user->accountOwnerId() === $user->id
            ? $this->exportService->build($user)
            : $this->exportService->buildForMember($user);

        $filename = 'qasa-export-'.now()->toDateString().'.json';

        return response()->streamDownload(function () use ($data): void {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * @throws Throwable
     */
    #[OA\Delete(
        path: '/api/v1/profile',
        summary: 'Delete (soft-delete) the account and revoke all tokens',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'password', type: 'string', nullable: true, description: 'Required for accounts with a password'),
                new OA\Property(property: 'confirmation', type: 'string', nullable: true, description: 'Must be "DELETE" for Google-only accounts'),
            ])
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 204, description: 'Account deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only the account owner can delete the account'),
            new OA\Response(response: 422, description: 'Invalid password or confirmation'),
        ]
    )]
    public function deleteAccount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->accountOwnerId() !== $user->id) {
            return response()->json(['message' => __('auth.delete_owner_only')], 403);
        }

        $request->validate(DeleteAccountData::rules());
        $data = DeleteAccountData::fromRequest($request);

        try {
            $this->deleteAccountAction->execute($user, $data);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/profile/accept-terms',
        summary: 'Record acceptance of the current terms of use / privacy policy version',
        description: 'For accounts that predate terms_accepted_at, and for re-accepting after '
            .'the version in config(\'gdpr.terms_version\') moves — see the terms_acceptance_required '
            .'flag on the User resource.',
        security: [['sanctum' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 204, description: 'Terms accepted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function acceptTerms(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->acceptTermsAction->execute($user);

        return response()->json(null, 204);
    }
}
