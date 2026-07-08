<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Application\Actions\CreateRateAction;
use App\Modules\Pricing\Application\Actions\DeleteRateAction;
use App\Modules\Pricing\Application\Contracts\RateResolverInterface;
use App\Modules\Pricing\Application\DTOs\RateData;
use App\Modules\Pricing\Domain\Models\Rate;
use App\Modules\Pricing\Presentation\Resources\RateResource;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(
    name: 'Rates',
    description: 'Date-effective billing rates (global / client / order level)'
)]
class RateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CreateRateAction $createAction,
        private readonly DeleteRateAction $deleteAction,
        private readonly RateResolverInterface $rateResolver,
    ) {}

    #[OA\Get(
        path: '/api/v1/rates',
        summary: 'List rate history',
        security: [['sanctum' => []]],
        tags: ['Rates'],
        parameters: [
            new OA\Parameter(name: 'level', description: 'Filter by level', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['user', 'client', 'order'])),
            new OA\Parameter(name: 'client_id', description: 'Filter by client', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'order_id', description: 'Filter by order', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Rate history, newest first',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Rate')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Rate::class);

        $rates = Rate::query()
            ->when($request->filled('level'), fn ($q) => $q->where('level', $request->string('level')->toString()))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->string('client_id')->toString()))
            ->when($request->filled('order_id'), fn ($q) => $q->where('order_id', $request->string('order_id')->toString()))
            ->orderByDesc('valid_from')
            ->orderBy('level')
            ->get();

        return RateResource::collection($rates);
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/rates',
        summary: 'Create a date-effective rate',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['level', 'rate'],
                properties: [
                    new OA\Property(property: 'level', type: 'string', enum: ['user', 'client', 'order']),
                    new OA\Property(property: 'rate', type: 'number', format: 'float', example: 45.5),
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid', nullable: true, description: 'Required for level=client'),
                    new OA\Property(property: 'order_id', type: 'string', format: 'uuid', nullable: true, description: 'Required for level=order'),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD'], nullable: true),
                    new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true, description: 'Defaults to today'),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Rates'],
        responses: [
            new OA\Response(response: 201, description: 'Rate created', content: new OA\JsonContent(ref: '#/components/schemas/Rate')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Rate::class);
        $request->validate(RateData::rules());

        /** @var User $user */
        $user = $request->user();

        try {
            $data = RateData::fromRequest($request);
            $rate = $this->createAction->execute($data, $user);

            return response()->json(RateResource::make($rate), 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Delete(
        path: '/api/v1/rates/{id}',
        summary: 'Delete a not-yet-effective rate',
        description: 'History is append-only: only rates with valid_from today or later can be deleted.',
        security: [['sanctum' => []]],
        tags: ['Rates'],
        parameters: [
            new OA\Parameter(name: 'id', description: 'Rate ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Rate deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Rate not found'),
            new OA\Response(response: 422, description: 'Rate already took effect and cannot be deleted'),
        ]
    )]
    public function destroy(Rate $rate): JsonResponse
    {
        $this->authorize('delete', $rate);

        try {
            $this->deleteAction->execute($rate);

            return response()->json(null, 204);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Get(
        path: '/api/v1/rates/effective',
        summary: 'Resolve the effective rate for a scope and date',
        security: [['sanctum' => []]],
        tags: ['Rates'],
        parameters: [
            new OA\Parameter(name: 'client_id', description: 'Client scope', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'order_id', description: 'Order scope (its client is included automatically)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'date', description: 'Work date, defaults to today', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resolved rate, or data=null when no rate applies',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'rate', type: 'number', format: 'float'),
                                new OA\Property(property: 'currency', type: 'string', nullable: true),
                                new OA\Property(property: 'level', type: 'string', enum: ['user', 'client', 'order']),
                                new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
                            ],
                            type: 'object',
                            nullable: true,
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Client or order not found'),
        ]
    )]
    public function effective(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => ['nullable', 'uuid'],
            'order_id' => ['nullable', 'uuid'],
            'date' => ['nullable', 'date'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $order = $request->filled('order_id')
            ? Order::forUser($user->accountOwnerId())->findOrFail($request->string('order_id')->toString())
            : null;
        $client = $request->filled('client_id')
            ? Client::forUser($user->accountOwnerId())->findOrFail($request->string('client_id')->toString())
            : $order?->client;

        $date = $request->filled('date')
            ? Carbon::parse($request->string('date')->toString())
            : today();

        $resolution = $this->rateResolver->resolve($user, $client, $order, $date);

        return response()->json([
            'data' => $resolution === null ? null : [
                'rate' => $resolution->rate,
                'currency' => $resolution->currency?->value,
                'level' => $resolution->level->value,
                'valid_from' => $resolution->validFrom->toDateString(),
            ],
        ]);
    }
}
