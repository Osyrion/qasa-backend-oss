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

class OrderItemController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly UpsertOrderItemAction $upsertAction,
    ) {}

    public function index(Order $order): AnonymousResourceCollection
    {
        $this->authorize('view', $order);

        return OrderItemResource::collection(
            $order->items()->orderBy('sort_order')->get()
        );
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $data = OrderItemData::fromRequest($request);
        $item = $this->upsertAction->execute($order, $data);

        return response()->json(OrderItemResource::make($item), 201);
    }

    public function update(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        $this->authorize('update', $order);

        $data = OrderItemData::fromRequest($request);
        $updated = $this->upsertAction->execute($order, $data, $item);

        return response()->json(OrderItemResource::make($updated));
    }

    public function destroy(Order $order, OrderItem $item): JsonResponse
    {
        $this->authorize('update', $order);

        if ($item->isTime() && $item->timeEntry?->is_invoiced) {
            return response()->json([
                'message' => __('orders.item_not_deletable_invoiced'),
            ], 422);
        }

        $item->delete();

        return response()->json(null, 204);
    }
}
