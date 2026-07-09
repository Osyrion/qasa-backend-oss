<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Controllers;

use App\Modules\Auth\Application\Actions\LoginAction;
use App\Modules\Auth\Application\Actions\RegisterUserAction;
use App\Modules\Auth\Application\Actions\UpdateProfileAction;
use App\Modules\Auth\Application\DTOs\LoginData;
use App\Modules\Auth\Application\DTOs\RegisterUserData;
use App\Modules\Auth\Application\DTOs\UpdateProfileData;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Auth\Presentation\Resources\UserResource;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly RegisterUserAction $registerAction,
        private readonly LoginAction $loginAction,
        private readonly UpdateProfileAction $updateProfileAction,
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
                required: ['name', 'surname', 'email', 'password'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Ján', maxLength: 100),
                    new OA\Property(property: 'surname', type: 'string', example: 'Novák', maxLength: 100),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jan@example.com', maxLength: 255),
                    new OA\Property(property: 'password', type: 'string', example: 'password123', maxLength: 255, minLength: 8),
                    new OA\Property(property: 'title', type: 'string', example: 'Ing.', nullable: true, maxLength: 100),
                    new OA\Property(property: 'default_currency', type: 'string', example: 'EUR', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'locale', type: 'string', example: 'sk'),
                    new OA\Property(property: 'country', type: 'string', example: 'SK'),
                    new OA\Property(property: 'device_name', type: 'string', example: 'mobile-app'),
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
        // users via `php artisan qasa:user`.
        abort_unless((bool) config('qasa.features.registration'), 404);

        $request->validate(RegisterUserData::rules());

        $data = RegisterUserData::fromRequest($request);
        $user = $this->registerAction->execute($data);

        $token = $user->createToken(
            $request->input('device_name', 'api-token')
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
                ]
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login successful',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'token', type: 'string', example: '1|abc123...'),
                ])
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid credentials',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Nesprávny email alebo heslo.'),
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

            return response()->json([
                'token' => $result['token'],
                'user' => UserResource::make($result['user']),
            ]);
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
        $request->user()->currentAccessToken()->delete();

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
                content: new OA\JsonContent(ref: '#/components/schemas/User')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        return response()->json(UserResource::make($request->user()));
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
                new OA\Property(property: 'ico', type: 'string', example: '12345678', nullable: true, maxLength: 20),
                new OA\Property(property: 'dic', type: 'string', example: '1234567890', nullable: true, maxLength: 20),
                new OA\Property(property: 'is_vat_payer', type: 'boolean', example: true, nullable: true),
                new OA\Property(property: 'tax_flat_rate', type: 'integer', example: 20, nullable: true),
                new OA\Property(property: 'default_currency', type: 'string', example: 'EUR', nullable: true, enum: ['CZK', 'EUR', 'USD']),
                new OA\Property(property: 'invoice_prefix', type: 'string', example: 'FA', nullable: true, maxLength: 10),
                new OA\Property(property: 'invoice_number_mask', type: 'string', example: '{YYYY}{NNNN}', nullable: true, maxLength: 40),
                new OA\Property(property: 'invoice_number_start', type: 'integer', example: 1, nullable: true),
                new OA\Property(property: 'locale', type: 'string', example: 'sk', nullable: true, maxLength: 5),
                new OA\Property(property: 'country', type: 'string', example: 'SK', nullable: true, maxLength: 2),
                new OA\Property(property: 'address', type: 'string', example: 'Hlavná 1', nullable: true),
                new OA\Property(property: 'city', type: 'string', example: 'Bratislava', nullable: true),
                new OA\Property(property: 'postal_code', type: 'string', example: '811 01', nullable: true, maxLength: 10),
                new OA\Property(property: 'vat_id', type: 'string', example: 'SK1234567890', nullable: true, maxLength: 20),
                new OA\Property(property: 'website', type: 'string', example: 'https://example.com', nullable: true, maxLength: 150),
                new OA\Property(property: 'invoice_footer_text', type: 'string', nullable: true, maxLength: 1000),
                new OA\Property(property: 'clockify_api_key', type: 'string', nullable: true, maxLength: 100),
                new OA\Property(property: 'clockify_workspace_id', type: 'string', nullable: true, maxLength: 50),     ])
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profile updated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/User')
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

        return response()->json(UserResource::make($user));
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
                content: new OA\JsonContent(ref: '#/components/schemas/User')
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

        return response()->json(UserResource::make($user->fresh()));
    }
}
