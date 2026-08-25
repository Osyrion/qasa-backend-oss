<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Controllers;

use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Domain\Models\AccountNotification;
use App\Modules\Shared\Enums\NotificationCategory;
use App\Modules\Shared\Presentation\Resources\NotificationResource;
use App\Modules\Shared\Support\Pagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

/**
 * The in-app notification centre.
 *
 * Every query is narrowed to the authenticated user as the *recipient*, not
 * merely to the account: the global scope and the RLS policy both key on the
 * account owner, so within a team they would happily return a colleague's
 * notifications. forRecipient() is what makes the inbox personal, and
 * NotificationPolicy enforces the same rule on the single-row endpoints.
 */
#[OA\Tag(name: 'Notifications', description: 'In-app notification centre')]
class NotificationController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/v1/notifications',
        summary: 'List the notifications addressed to the authenticated user',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'unread', in: 'query', description: 'Only unread when truthy', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string', enum: ['invoice', 'quote', 'tax', 'billing', 'banking', 'system'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated notifications',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Notification')),
                    new OA\Property(property: 'meta', type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AccountNotification::class);

        $query = AccountNotification::query()
            ->forRecipient($this->userId($request))
            ->latest();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $category = NotificationCategory::tryFrom($request->string('category')->toString());

        if ($category !== null) {
            $query->category($category->value);
        }

        return NotificationResource::collection(
            $query->paginate(Pagination::perPage($request))
        );
    }

    #[OA\Get(
        path: '/api/v1/notifications/unread-count',
        summary: 'Number of unread notifications for the authenticated user',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Unread count',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'count', type: 'integer')])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function unreadCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AccountNotification::class);

        $count = AccountNotification::query()
            ->forRecipient($this->userId($request))
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    #[OA\Patch(
        path: '/api/v1/notifications/{notification}/read',
        summary: 'Mark one notification as read',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The notification',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Notification'),
                ])
            ),
            new OA\Response(response: 403, description: 'Addressed to somebody else'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function markRead(AccountNotification $notification): NotificationResource
    {
        $this->authorize('update', $notification);

        // Idempotent on purpose: the front end marks on open, and re-opening
        // a notification must not move the timestamp it already carries.
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return new NotificationResource($notification);
    }

    #[OA\Post(
        path: '/api/v1/notifications/read-all',
        summary: 'Mark every unread notification of the authenticated user as read',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'How many were marked',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'marked', type: 'integer')])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function markAllRead(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AccountNotification::class);

        $marked = AccountNotification::query()
            ->forRecipient($this->userId($request))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['marked' => $marked]);
    }

    #[OA\Delete(
        path: '/api/v1/notifications/{notification}',
        summary: 'Delete a notification',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Addressed to somebody else'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(AccountNotification $notification): JsonResponse
    {
        $this->authorize('delete', $notification);

        $notification->delete();

        return response()->json(null, 204);
    }

    private function userId(Request $request): string
    {
        /** @var Actor $user */
        $user = $request->user();

        return $user->actorId();
    }
}
