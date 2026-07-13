<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Calendar\Application\Actions\CreateEventAction;
use App\Modules\Calendar\Application\Actions\UpdateEventAction;
use App\Modules\Calendar\Application\Contracts\EventRepositoryInterface;
use App\Modules\Calendar\Application\DTOs\EventData;
use App\Modules\Calendar\Application\DTOs\EventRangeData;
use App\Modules\Calendar\Domain\Models\Event;
use App\Modules\Calendar\Presentation\Resources\EventResource;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Support\Pagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Calendar', description: 'Tenant calendar events')]
class EventController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly EventRepositoryInterface $repository,
        private readonly CreateEventAction $createAction,
        private readonly UpdateEventAction $updateAction,
    ) {}

    #[OA\Get(
        path: '/api/v1/events',
        summary: 'List events',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'from', description: 'Range start (inclusive)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', description: 'Range end (inclusive)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', description: 'Page size', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'order_id', description: 'Only events linked to this order', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(
                name: 'include',
                description: 'Set to order_deadlines to additionally mix in read-only virtual items for order deadlines within range',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['order_deadlines'])
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Events overlapping the given range, ordered by start time',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Event')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $this->authorize('viewAny', Event::class);

        $range = EventRangeData::validateAndCreate($request->all());
        $orderId = $request->filled('order_id') ? $request->string('order_id')->toString() : null;

        $events = $this->repository->paginate(
            perPage: Pagination::perPage($request),
            from: $range->from !== null ? Carbon::parse($range->from) : null,
            to: $range->to !== null ? Carbon::parse($range->to) : null,
            orderId: $orderId,
        );

        $resource = EventResource::collection($events);

        if ($request->query('include') !== 'order_deadlines') {
            return $resource;
        }

        /** @var User $user */
        $user = $request->user();

        $payload = $resource->response()->getData(true);
        $payload['data'] = [
            ...$payload['data'],
            ...$this->orderDeadlineItems($user->accountOwnerId(), $range),
        ];

        return response()->json($payload);
    }

    /**
     * Read-only virtual "events" for orders with a deadline in range —
     * never persisted, never exported (ICS/CSV export only ever sees real
     * events, so a re-import can't create duplicates), and re-computed from
     * live order data on every request, so an edited deadline is reflected
     * immediately with no synchronization to worry about.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderDeadlineItems(string $ownerId, EventRangeData $range): array
    {
        $query = Order::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->whereIn('status', ['active', 'paused'])
            ->whereNotNull('deadline');

        if ($range->from !== null) {
            $query->where('deadline', '>=', $range->from);
        }

        if ($range->to !== null) {
            $query->where('deadline', '<=', $range->to);
        }

        return $query->with('client:id,client_type,title,name,surname,company_name')
            ->get()
            ->map(function (Order $order): array {
                // whereNotNull('deadline') above guarantees this.
                assert($order->deadline !== null);

                $startsAt = $order->deadline->clone()->startOfDay();

                return [
                    'id' => null,
                    'type' => 'order_deadline',
                    'title' => $order->name,
                    'description' => null,
                    'location' => null,
                    'color' => $order->color,
                    'effective_color' => $order->color,
                    'is_all_day' => true,
                    'starts_at' => $startsAt->toISOString(),
                    'ends_at' => $startsAt->clone()->addDay()->toISOString(),
                    'source' => null,
                    'order_id' => $order->id,
                    'order' => [
                        'id' => $order->id,
                        'name' => $order->name,
                        'color' => $order->color,
                        'client_display_name' => $order->client?->display_name,
                    ],
                    'created_at' => null,
                    'updated_at' => null,
                ];
            })
            ->all();
    }

    #[OA\Get(
        path: '/api/v1/events/{event}',
        summary: 'Show an event',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Event', content: new OA\JsonContent(ref: '#/components/schemas/Event')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Event not found'),
        ]
    )]
    public function show(Event $event): EventResource
    {
        $this->authorize('view', $event);

        return EventResource::make($event->load('order.client'));
    }

    #[OA\Post(
        path: '/api/v1/events',
        summary: 'Create an event',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'starts_at'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true, description: 'Required unless is_all_day'),
                    new OA\Property(property: 'is_all_day', type: 'boolean', default: false),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'location', type: 'string', nullable: true),
                    new OA\Property(property: 'color', type: 'string', nullable: true, example: '#3B82F6'),
                    new OA\Property(property: 'order_id', type: 'string', format: 'uuid', nullable: true),
                ]
            )
        ),
        tags: ['Calendar'],
        responses: [
            new OA\Response(response: 201, description: 'Event created', content: new OA\JsonContent(ref: '#/components/schemas/Event')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'order_id belongs to another account'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Event::class);

        $data = EventData::validateAndCreate($request->all());

        // Global user scope 404s an order_id belonging to another account.
        if ($data->order_id !== null) {
            Order::query()->findOrFail($data->order_id);
        }

        /** @var User $user */
        $user = $request->user();

        $event = $this->createAction->execute($data, $user->accountOwnerId());

        return response()->json(EventResource::make($event->load('order.client')), 201);
    }

    #[OA\Put(
        path: '/api/v1/events/{event}',
        summary: 'Update an event',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'starts_at'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true, description: 'Required unless is_all_day'),
                    new OA\Property(property: 'is_all_day', type: 'boolean', default: false),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'location', type: 'string', nullable: true),
                    new OA\Property(property: 'color', type: 'string', nullable: true, example: '#3B82F6'),
                    new OA\Property(property: 'order_id', type: 'string', format: 'uuid', nullable: true),
                ]
            )
        ),
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Event updated', content: new OA\JsonContent(ref: '#/components/schemas/Event')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Event, or order_id belonging to another account, not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = EventData::validateAndCreate($request->all());

        // Global user scope 404s an order_id belonging to another account.
        if ($data->order_id !== null) {
            Order::query()->findOrFail($data->order_id);
        }

        $updated = $this->updateAction->execute($event, $data);

        return response()->json(EventResource::make($updated->load('order.client')));
    }

    #[OA\Delete(
        path: '/api/v1/events/{event}',
        summary: 'Delete an event',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Event deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Event not found'),
        ]
    )]
    public function destroy(Event $event): JsonResponse
    {
        $this->authorize('delete', $event);

        $this->repository->delete($event);

        return response()->json(null, 204);
    }
}
