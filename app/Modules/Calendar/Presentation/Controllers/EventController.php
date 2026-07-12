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
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Event::class);

        $range = EventRangeData::validateAndCreate($request->all());

        $events = $this->repository->paginate(
            perPage: (int) $request->input('per_page', 20),
            from: $range->from !== null ? Carbon::parse($range->from) : null,
            to: $range->to !== null ? Carbon::parse($range->to) : null,
        );

        return EventResource::collection($events);
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

        return EventResource::make($event);
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
                ]
            )
        ),
        tags: ['Calendar'],
        responses: [
            new OA\Response(response: 201, description: 'Event created', content: new OA\JsonContent(ref: '#/components/schemas/Event')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Event::class);

        $data = EventData::validateAndCreate($request->all());

        /** @var User $user */
        $user = $request->user();

        $event = $this->createAction->execute($data, $user->accountOwnerId());

        return response()->json(EventResource::make($event), 201);
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
            new OA\Response(response: 404, description: 'Event not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = EventData::validateAndCreate($request->all());

        $updated = $this->updateAction->execute($event, $data);

        return response()->json(EventResource::make($updated));
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
