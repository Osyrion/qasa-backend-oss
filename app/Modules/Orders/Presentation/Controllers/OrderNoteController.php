<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Controllers;

use App\Modules\Orders\Application\DTOs\OrderNoteData;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderNote;
use App\Modules\Orders\Presentation\Resources\OrderNoteResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class OrderNoteController extends Controller
{
    use AuthorizesRequests;

    public function index(Order $order): AnonymousResourceCollection
    {
        $this->authorize('view', $order);

        return OrderNoteResource::collection(
            $order->notes()->orderBy('created_at', 'desc')->get()
        );
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $data = OrderNoteData::fromRequest($request);

        /** @var OrderNote $note */
        $note = $order->notes()->create([
            'user_id' => $request->user()->id,
            'content' => $data->content,
        ]);

        return response()->json(OrderNoteResource::make($note), 201);
    }

    public function destroy(Order $order, OrderNote $note): JsonResponse
    {
        $this->authorize('update', $order);

        // Author may always delete their own note; orders.manage covers the rest.
        if ((string) $note->user_id !== (string) request()->user()->id
            && ! request()->user()->can('orders.manage')
        ) {
            return response()->json(['message' => __('orders.notes_delete_own_only')], 403);
        }

        $note->delete();

        return response()->json(null, 204);
    }
}
