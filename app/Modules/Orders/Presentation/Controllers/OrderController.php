<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Controllers;

use App\Modules\Orders\Application\Actions\CreateOrderAction;
use App\Modules\Orders\Application\Actions\DeleteOrderAction;
use App\Modules\Orders\Application\Actions\UpdateOrderAction;
use App\Modules\Orders\Application\Contracts\OrderRepositoryInterface;
use App\Modules\Orders\Application\DTOs\OrderData;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Presentation\Resources\OrderResource;
use App\Modules\Shared\Support\Pagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(
    name: 'Orders',
    description: 'Order management endpoints'
)]
class OrderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrderRepositoryInterface $repository,
        private readonly CreateOrderAction $createAction,
        private readonly UpdateOrderAction $updateAction,
        private readonly DeleteOrderAction $deleteAction,
    ) {
        $this->authorizeResource(Order::class, 'order');
    }

    #[OA\Get(
        path: '/api/v1/orders',
        summary: 'List orders',
        security: [['sanctum' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(
                name: 'per_page',
                description: 'Items per page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 20)
            ),
            new OA\Parameter(
                name: 'status',
                description: 'Filter by status',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['active', 'paused', 'completed', 'archived'])
            ),
            new OA\Parameter(
                name: 'client_id',
                description: 'Filter by client',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
            new OA\Parameter(
                name: 'billing_type',
                description: 'Filter by billing type',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['hourly', 'daily', 'monthly', 'fixed_per_item', 'mixed'])
            ),
            new OA\Parameter(
                name: 'personal',
                description: 'Filter personal orders',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'boolean')
            ),
            new OA\Parameter(
                name: 'billable',
                description: 'Filter billable orders',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'boolean')
            ),
            new OA\Parameter(
                name: 'search',
                description: 'Search term',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'sort',
                description: 'Sort field',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'direction',
                description: 'Sort direction',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of orders',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Order')),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $this->repository->paginate(
            perPage: Pagination::perPage($request),
            filters: $request->only([
                'status',
                'client_id',
                'billing_type',
                'personal',
                'billable',
                'search',
                'sort',
                'direction',
            ]),
        );

        return OrderResource::collection($orders);
    }

    #[OA\Get(
        path: '/api/v1/orders/{id}',
        summary: 'Get order details',
        security: [['sanctum' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Order ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Order details',
                content: new OA\JsonContent(ref: '#/components/schemas/Order')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Order not found'),
        ]
    )]
    public function show(Order $order): OrderResource
    {
        $order->load(['client', 'items', 'notes']);

        return OrderResource::make($order);
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/orders',
        summary: 'Create order',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'billing_type'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'billing_type', type: 'string', enum: ['hourly', 'daily', 'monthly', 'fixed_per_item', 'mixed']),
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'color', type: 'string', example: '#3B82F6', nullable: true, maxLength: 7),
                    new OA\Property(property: 'readme', type: 'string', nullable: true),
                    new OA\Property(property: 'rate', type: 'number', nullable: true),
                    new OA\Property(property: 'currency', type: 'string', nullable: true, enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'estimated_hours', type: 'number', nullable: true),
                    new OA\Property(property: 'estimated_price', type: 'number', nullable: true),
                    new OA\Property(property: 'deadline', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'status', type: 'string', default: 'active', enum: ['active', 'paused', 'completed', 'archived']),
                ]
            )
        ),
        tags: ['Orders'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Order created',
                content: new OA\JsonContent(ref: '#/components/schemas/Order')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = OrderData::fromRequest($request);
        $order = $this->createAction->execute($data, $request->user()->accountOwnerId());

        return response()->json(OrderResource::make($order), 201);
    }

    /**
     * @throws Throwable
     */
    #[OA\Put(
        path: '/api/v1/orders/{id}',
        summary: 'Update order',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'billing_type'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'billing_type', type: 'string', enum: ['hourly', 'daily', 'monthly', 'fixed_per_item', 'mixed']),
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'color', type: 'string', example: '#3B82F6', nullable: true, maxLength: 7),
                    new OA\Property(property: 'readme', type: 'string', nullable: true),
                    new OA\Property(property: 'rate', type: 'number', nullable: true),
                    new OA\Property(property: 'currency', type: 'string', nullable: true, enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'estimated_hours', type: 'number', nullable: true),
                    new OA\Property(property: 'estimated_price', type: 'number', nullable: true),
                    new OA\Property(property: 'deadline', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'paused', 'completed', 'archived']),
                ]
            )
        ),
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Order ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Order updated',
                content: new OA\JsonContent(ref: '#/components/schemas/Order')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Order $order): JsonResponse
    {
        $data = OrderData::fromRequest($request);
        $updated = $this->updateAction->execute($order, $data);

        return response()->json(OrderResource::make($updated));
    }

    /**
     * @throws Throwable
     */
    #[OA\Delete(
        path: '/api/v1/orders/{id}',
        summary: 'Delete order',
        security: [['sanctum' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Order ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Order deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Cannot delete order with existing invoices'),
        ]
    )]
    public function destroy(Order $order): JsonResponse
    {
        $this->deleteAction->execute($order);

        return response()->json(null, 204);
    }
}
