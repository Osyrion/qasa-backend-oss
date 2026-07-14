<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Controllers;

use App\Modules\Orders\Application\Actions\UpsertOrderItemAction;
use App\Modules\Orders\Application\DTOs\OrderItemData;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderItem;
use App\Modules\Orders\Presentation\Resources\OrderItemResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Order Items',
    description: 'Manage order line items'
)]
class OrderItemController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly UpsertOrderItemAction $upsertAction,
    ) {}

    #[OA\Get(
        path: '/api/v1/orders/{order}/items',
        summary: 'List order items',
        security: [['sanctum' => []]],
        tags: ['Order Items'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of order items',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderItem'))
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Order $order): AnonymousResourceCollection
    {
        $this->authorize('view', $order);

        return OrderItemResource::collection(
            $order->items()->orderBy('sort_order')->get()
        );
    }

    #[OA\Post(
        path: '/api/v1/orders/{order}/items',
        summary: 'Create order item',
        security: [['sanctum' => []]],
        tags: ['Order Items'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'description', 'quantity', 'unit', 'unit_price', 'vat_rate'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['service', 'product']),
                    new OA\Property(property: 'description', type: 'string', example: 'Consulting services', maxLength: 500),
                    new OA\Property(property: 'quantity', type: 'number', format: 'float', example: 8.5),
                    new OA\Property(property: 'unit', type: 'string', example: 'h', maxLength: 20),
                    new OA\Property(property: 'unit_price', type: 'number', format: 'float', example: 50.00),
                    new OA\Property(property: 'vat_rate', type: 'number', format: 'float', example: 20, description: 'VAT rate in percent (0-100)'),
                    new OA\Property(property: 'sort_order', type: 'integer', example: 1, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Order item created',
                content: new OA\JsonContent(ref: '#/components/schemas/OrderItem')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $data = OrderItemData::fromRequest($request);
        $item = $this->upsertAction->execute($order, $data);

        return response()->json(OrderItemResource::make($item), 201);
    }

    #[OA\Put(
        path: '/api/v1/orders/{order}/items/{item}',
        summary: 'Update order item',
        security: [['sanctum' => []]],
        tags: ['Order Items'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'description', 'quantity', 'unit', 'unit_price', 'vat_rate'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['service', 'product']),
                    new OA\Property(property: 'description', type: 'string', example: 'Consulting services', maxLength: 500),
                    new OA\Property(property: 'quantity', type: 'number', format: 'float', example: 8.5),
                    new OA\Property(property: 'unit', type: 'string', example: 'h', maxLength: 20),
                    new OA\Property(property: 'unit_price', type: 'number', format: 'float', example: 50.00),
                    new OA\Property(property: 'vat_rate', type: 'number', format: 'float', example: 20, description: 'VAT rate in percent (0-100)'),
                    new OA\Property(property: 'sort_order', type: 'integer', example: 1, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Order item updated',
                content: new OA\JsonContent(ref: '#/components/schemas/OrderItem')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        $this->authorize('update', $order);

        $data = OrderItemData::fromRequest($request);
        $updated = $this->upsertAction->execute($order, $data, $item);

        return response()->json(OrderItemResource::make($updated));
    }

    #[OA\Delete(
        path: '/api/v1/orders/{order}/items/{item}',
        summary: 'Delete order item',
        security: [['sanctum' => []]],
        tags: ['Order Items'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Order item deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Cannot delete invoiced item'),
        ]
    )]
    public function destroy(Order $order, OrderItem $item): JsonResponse
    {
        $this->authorize('update', $order);

        $item->delete();

        return response()->json(null, 204);
    }
}
