<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Controllers;

use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderAttachment;
use App\Modules\Orders\Presentation\Resources\OrderAttachmentResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class OrderAttachmentController extends Controller
{
    use AuthorizesRequests;

    private const array ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain', 'text/csv',
    ];

    private const int|float MAX_FILE_SIZE_BYTES = 20 * 1024 * 1024; // 20MB

    public function index(Order $order): AnonymousResourceCollection
    {
        $this->authorize('view', $order);

        return OrderAttachmentResource::collection(
            $order->attachments()->orderBy('sort_order')->get()
        );
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';

        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return response()->json(['message' => __('orders.attachment_file_type_not_allowed')], 422);
        }

        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            return response()->json(['message' => __('orders.attachment_too_large')], 422);
        }

        $disk = config('filesystems.default', 'local');
        $path = $file->store(
            "orders/{$order->id}/attachments",
            $disk,
        );

        if ($path === false) {
            return response()->json(['message' => __('orders.attachment_save_failed')], 500);
        }

        $nextSortOrder = $order->attachments()->max('sort_order') + 1;

        /** @var OrderAttachment $attachment */
        $attachment = $order->attachments()->create([
            'user_id' => $request->user()->id,
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'size_bytes' => $file->getSize(),
            'label' => $request->input('label'),
            'sort_order' => $nextSortOrder,
        ]);

        return response()->json(OrderAttachmentResource::make($attachment), 201);
    }

    public function destroy(Order $order, OrderAttachment $attachment): JsonResponse
    {
        $this->authorize('update', $order);

        if (! $attachment->isExternal() && $attachment->path !== null) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }

        $attachment->delete();

        return response()->json(null, 204);
    }
}
