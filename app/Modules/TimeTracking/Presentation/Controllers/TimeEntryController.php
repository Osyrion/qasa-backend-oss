<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\TimeTracking\Application\Contracts\WorkLogRepositoryInterface;
use App\Modules\TimeTracking\Application\DTOs\TimeEntryData;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use App\Modules\TimeTracking\Presentation\Resources\TimeEntryResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'TimeTracking',
    description: 'Time tracking endpoints'
)]
class TimeEntryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly WorkLogRepositoryInterface $repository,
    ) {}

    #[OA\Get(
        path: '/api/v1/time-entries',
        summary: 'List time entries',
        security: [['sanctum' => []]],
        tags: ['TimeTracking'],
        parameters: [
            new OA\Parameter(name: 'order_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'is_billable', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'is_invoiced', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'year', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of time entries'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TimeEntry::class);

        $timeEntries = $this->repository->paginate(
            perPage: (int) $request->input('per_page', 20),
            filters: $request->only(['order_id', 'is_billable', 'is_invoiced', 'date_from', 'date_to', 'year']),
        );

        return TimeEntryResource::collection($timeEntries);
    }

    #[OA\Get(
        path: '/api/v1/time-entries/{time_entry}',
        summary: 'Show a time entry',
        security: [['sanctum' => []]],
        tags: ['TimeTracking'],
        parameters: [
            new OA\Parameter(name: 'time_entry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Time entry'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(TimeEntry $timeEntry): TimeEntryResource
    {
        $this->authorize('view', $timeEntry);

        return TimeEntryResource::make($timeEntry);
    }

    #[OA\Post(
        path: '/api/v1/time-entries',
        summary: 'Create a time entry',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id', 'started_at'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'order_item_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 1000),
                    new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'ended_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'duration_seconds', type: 'integer', nullable: true),
                    new OA\Property(property: 'rate_override', type: 'number', nullable: true),
                    new OA\Property(property: 'vat_rate', type: 'number'),
                    new OA\Property(property: 'is_billable', type: 'boolean'),
                ]
            )
        ),
        tags: ['TimeTracking'],
        responses: [
            new OA\Response(response: 201, description: 'Time entry created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TimeEntry::class);

        /** @var User $user */
        $user = $request->user();
        $owner = $user->accountOwner();

        $request->validate(TimeEntryData::rules($owner->id, $owner->country));
        $data = TimeEntryData::fromRequest($request);

        try {
            // Global user scope 404s orders of other accounts
            $order = Order::query()->findOrFail($data->order_id);
            $this->assertItemBelongsToOrder($order, $data->order_item_id);

            $timeEntry = $this->repository->create([
                'user_id' => $owner->id,
                'order_id' => $order->id,
                'order_item_id' => $data->order_item_id,
                'description' => $data->description,
                'started_at' => $data->started_at,
                'ended_at' => $data->ended_at,
                'duration_seconds' => $this->resolveDuration($data),
                'rate_override' => $data->rate_override,
                'vat_rate' => $data->vat_rate,
                'is_billable' => $data->is_billable,
                'is_invoiced' => false,
                'source' => 'manual',
                'external_id' => null,
            ]);
        } catch (DomainException $e) {
            return $this->domainErrorResponse($e);
        }

        return response()->json(TimeEntryResource::make($timeEntry), 201);
    }

    #[OA\Put(
        path: '/api/v1/time-entries/{time_entry}',
        summary: 'Update a time entry',
        security: [['sanctum' => []]],
        tags: ['TimeTracking'],
        parameters: [
            new OA\Parameter(name: 'time_entry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Time entry updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error, or entry already invoiced'),
        ]
    )]
    public function update(Request $request, TimeEntry $timeEntry): JsonResponse
    {
        $this->authorize('update', $timeEntry);

        if ($timeEntry->isInvoiced()) {
            return $this->domainErrorResponse(DomainException::because(__('time_tracking.entry_already_invoiced')));
        }

        /** @var User $user */
        $user = $request->user();
        $owner = $user->accountOwner();

        $request->validate(TimeEntryData::rules($owner->id, $owner->country));
        $data = TimeEntryData::fromRequest($request);

        try {
            $order = Order::query()->findOrFail($data->order_id);
            $this->assertItemBelongsToOrder($order, $data->order_item_id);

            $updated = $this->repository->update($timeEntry, [
                'order_id' => $order->id,
                'order_item_id' => $data->order_item_id,
                'description' => $data->description,
                'started_at' => $data->started_at,
                'ended_at' => $data->ended_at,
                'duration_seconds' => $this->resolveDuration($data),
                'rate_override' => $data->rate_override,
                'vat_rate' => $data->vat_rate,
                'is_billable' => $data->is_billable,
            ]);
        } catch (DomainException $e) {
            return $this->domainErrorResponse($e);
        }

        return response()->json(TimeEntryResource::make($updated));
    }

    #[OA\Delete(
        path: '/api/v1/time-entries/{time_entry}',
        summary: 'Delete a time entry',
        security: [['sanctum' => []]],
        tags: ['TimeTracking'],
        parameters: [
            new OA\Parameter(name: 'time_entry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Time entry deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Entry already invoiced'),
        ]
    )]
    public function destroy(TimeEntry $timeEntry): JsonResponse
    {
        $this->authorize('delete', $timeEntry);

        if ($timeEntry->isInvoiced()) {
            return $this->domainErrorResponse(DomainException::because(__('time_tracking.entry_already_invoiced')));
        }

        $this->repository->delete($timeEntry);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/time-entries/start',
        summary: 'Start a running timer',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 1000),
                    new OA\Property(property: 'is_billable', type: 'boolean'),
                ]
            )
        ),
        tags: ['TimeTracking'],
        responses: [
            new OA\Response(response: 201, description: 'Timer started'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'A timer is already running'),
        ]
    )]
    public function start(Request $request): JsonResponse
    {
        $this->authorize('create', TimeEntry::class);

        $request->validate([
            'order_id' => ['required', 'uuid', 'exists:orders,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_billable' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $ownerId = $user->accountOwnerId();

        try {
            if (TimeEntry::query()->running()->exists()) {
                throw DomainException::because(__('time_tracking.timer_already_running'));
            }

            // Global user scope 404s orders of other accounts
            $order = Order::query()->findOrFail($request->string('order_id')->toString());

            $timeEntry = $this->repository->create([
                'user_id' => $ownerId,
                'order_id' => $order->id,
                'order_item_id' => null,
                'description' => $request->filled('description') ? $request->string('description')->toString() : null,
                'started_at' => now(),
                'ended_at' => null,
                'duration_seconds' => null,
                'rate_override' => null,
                'vat_rate' => 0,
                'is_billable' => $request->boolean('is_billable', true),
                'is_invoiced' => false,
                'source' => 'manual',
                'external_id' => null,
            ]);
        } catch (DomainException $e) {
            return $this->domainErrorResponse($e);
        }

        return response()->json(TimeEntryResource::make($timeEntry), 201);
    }

    #[OA\Post(
        path: '/api/v1/time-entries/{time_entry}/stop',
        summary: 'Stop a running timer',
        security: [['sanctum' => []]],
        tags: ['TimeTracking'],
        parameters: [
            new OA\Parameter(name: 'time_entry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Timer stopped'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Timer is not running'),
        ]
    )]
    public function stop(TimeEntry $timeEntry): JsonResponse
    {
        $this->authorize('update', $timeEntry);

        if (! $timeEntry->isRunning()) {
            return $this->domainErrorResponse(DomainException::because(__('time_tracking.timer_not_running')));
        }

        $timeEntry->stop()->save();

        return response()->json(TimeEntryResource::make($timeEntry));
    }

    private function resolveDuration(TimeEntryData $data): ?int
    {
        if ($data->duration_seconds !== null) {
            return $data->duration_seconds;
        }

        if ($data->ended_at !== null) {
            return (int) Carbon::parse($data->started_at)->diffInSeconds(Carbon::parse($data->ended_at));
        }

        return null;
    }

    /**
     * @throws DomainException
     */
    private function assertItemBelongsToOrder(Order $order, ?string $orderItemId): void
    {
        if ($orderItemId === null) {
            return;
        }

        if (! $order->items()->whereKey($orderItemId)->exists()) {
            throw DomainException::because(__('time_tracking.item_not_in_order'));
        }
    }

    private function domainErrorResponse(DomainException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }
}
