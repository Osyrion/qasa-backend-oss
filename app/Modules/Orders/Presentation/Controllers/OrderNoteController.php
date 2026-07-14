<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Orders\Application\DTOs\OrderNoteData;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderNote;
use App\Modules\Orders\Presentation\Resources\OrderNoteResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Order Notes',
    description: 'Manage internal notes on orders'
)]
class OrderNoteController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/v1/orders/{order}/notes',
        summary: 'List order notes',
        security: [['sanctum' => []]],
        tags: ['Order Notes'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of order notes',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderNote'))
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Order $order): AnonymousResourceCollection
    {
        $this->authorize('view', $order);

        return OrderNoteResource::collection(
            $order->notes()->orderBy('created_at', 'desc')->get()
        );
    }

    #[OA\Post(
        path: '/api/v1/orders/{order}/notes',
        summary: 'Create order note',
        security: [['sanctum' => []]],
        tags: ['Order Notes'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['content'],
                properties: [
                    new OA\Property(property: 'content', type: 'string', example: 'Customer requested expedited delivery'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Order note created',
                content: new OA\JsonContent(ref: '#/components/schemas/OrderNote')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $data = OrderNoteData::fromRequest($request);

        /** @var User $user */
        $user = $request->user();

        /** @var OrderNote $note */
        $note = $order->notes()->create([
            'user_id' => $user->id,
            'content' => $data->content,
        ]);

        return response()->json(OrderNoteResource::make($note), 201);
    }

    #[OA\Delete(
        path: '/api/v1/orders/{order}/notes/{note}',
        summary: 'Delete order note',
        security: [['sanctum' => []]],
        tags: ['Order Notes'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'note', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Order note deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden or only author and managers can delete'),
        ]
    )]
    public function destroy(Order $order, OrderNote $note): JsonResponse
    {
        $this->authorize('update', $order);

        /** @var User $user */
        $user = request()->user();

        // Author may always delete their own note; orders.manage covers the rest.
        if ((string) $note->user_id !== (string) $user->id
            && ! $user->can('orders.manage')
        ) {
            return response()->json(['message' => __('orders.notes_delete_own_only')], 403);
        }

        $note->delete();

        return response()->json(null, 204);
    }
}
