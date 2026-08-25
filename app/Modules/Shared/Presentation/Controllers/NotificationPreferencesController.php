<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Controllers;

use App\Modules\Shared\Application\DTOs\NotificationPreferencesData;
use App\Modules\Shared\Domain\Contracts\ManagesNotificationPreferences;
use App\Modules\Shared\Domain\Contracts\ProvidesNotificationPreferences;
use App\Modules\Shared\Enums\NotificationCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

/**
 * Unlike AutomationSettingsController (account-wide, owner-only), this is a
 * personal preference: it gates only the caller's own in-app notification
 * centre, so each team member reads/writes their own row with no owner
 * check — the same reasoning as NotificationController's own endpoints.
 */
#[OA\Tag(name: 'Notifications', description: 'In-app notification centre')]
class NotificationPreferencesController extends Controller
{
    #[OA\Get(
        path: '/api/v1/notifications/preferences',
        summary: 'Show the caller\'s in-app notification category preferences',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Current preferences',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'notify_invoice_enabled', type: 'boolean'),
                    new OA\Property(property: 'notify_quote_enabled', type: 'boolean'),
                    new OA\Property(property: 'notify_tax_enabled', type: 'boolean'),
                    new OA\Property(property: 'notify_billing_enabled', type: 'boolean'),
                    new OA\Property(property: 'notify_banking_enabled', type: 'boolean'),
                    new OA\Property(property: 'notify_system_enabled', type: 'boolean'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var ProvidesNotificationPreferences $user */
        $user = $request->user();

        return response()->json($this->status($user));
    }

    #[OA\Put(
        path: '/api/v1/notifications/preferences',
        summary: 'Update the caller\'s in-app notification category preferences',
        description: 'Partial update — omitted fields are left unchanged. Only gates the in-app (database) channel, never e-mail or the automation trigger that decided to notify.',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'notify_invoice_enabled', type: 'boolean'),
                new OA\Property(property: 'notify_quote_enabled', type: 'boolean'),
                new OA\Property(property: 'notify_tax_enabled', type: 'boolean'),
                new OA\Property(property: 'notify_billing_enabled', type: 'boolean'),
                new OA\Property(property: 'notify_banking_enabled', type: 'boolean'),
                new OA\Property(property: 'notify_system_enabled', type: 'boolean'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $request->validate(NotificationPreferencesData::rules());

        /** @var ManagesNotificationPreferences $user */
        $user = $request->user();

        $changes = [];

        foreach (NotificationCategory::cases() as $category) {
            $field = self::field($category);

            if ($request->has($field)) {
                $changes[$category->value] = $request->boolean($field);
            }
        }

        $user->updateNotificationPreferences($changes);

        return response()->json($this->status($user));
    }

    /**
     * @return array{notify_invoice_enabled: bool, notify_quote_enabled: bool, notify_tax_enabled: bool, notify_billing_enabled: bool, notify_banking_enabled: bool, notify_system_enabled: bool}
     */
    private function status(ProvidesNotificationPreferences $user): array
    {
        $status = [];

        foreach (NotificationCategory::cases() as $category) {
            $status[self::field($category)] = $user->wantsNotificationCategory($category);
        }

        /** @var array{notify_invoice_enabled: bool, notify_quote_enabled: bool, notify_tax_enabled: bool, notify_billing_enabled: bool, notify_banking_enabled: bool, notify_system_enabled: bool} $status */
        return $status;
    }

    /**
     * The request/response field for a category. The wire shape predates the
     * enum and is what the front end sends, so it is derived here rather than
     * the six column names being retyped.
     */
    private static function field(NotificationCategory $category): string
    {
        return "notify_{$category->value}_enabled";
    }
}
