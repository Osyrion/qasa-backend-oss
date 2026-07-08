<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\TimeTracking\Application\Actions\SyncClockifyAction;
use App\Modules\TimeTracking\Application\DTOs\ClockifySyncData;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(
    name: 'TimeTracking',
    description: 'Time tracking endpoints'
)]
class ClockifySyncController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SyncClockifyAction $syncAction,
    ) {}

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/time-entries/sync/clockify',
        summary: 'Sync time entries from Clockify into an order',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id', 'date_from', 'date_to'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'date_from', type: 'string', format: 'date'),
                    new OA\Property(property: 'date_to', type: 'string', format: 'date'),
                ]
            )
        ),
        tags: ['TimeTracking'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sync result',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'created', type: 'integer'),
                    new OA\Property(property: 'updated', type: 'integer'),
                    new OA\Property(property: 'skipped', type: 'integer'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Validation error or Clockify not configured'),
        ]
    )]
    public function sync(Request $request): JsonResponse
    {
        $this->authorize('create', TimeEntry::class);

        $request->validate(ClockifySyncData::rules());
        $data = ClockifySyncData::fromRequest($request);

        // Global user scope 404s orders of other accounts
        $order = Order::query()->findOrFail($data->order_id);

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->syncAction->execute($user, $order, $data);

            return response()->json($result);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
