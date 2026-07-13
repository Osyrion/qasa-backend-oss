<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\TimeTracking\Application\Actions\ImportCsvAction;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'TimeTracking',
    description: 'Time tracking endpoints'
)]
class TimeEntryImportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ImportCsvAction $importAction,
    ) {}

    #[OA\Post(
        path: '/api/v1/time-entries/import/csv',
        summary: 'Import time entries from a Toggl or Clockify CSV export',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file', 'order_id'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'order_id', type: 'string', format: 'uuid'),
                    ]
                )
            )
        ),
        tags: ['TimeTracking'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Import result',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'created', type: 'integer'),
                    new OA\Property(property: 'skipped', type: 'integer'),
                    new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Order not found'),
            new OA\Response(response: 422, description: 'Validation error or unrecognised CSV format'),
        ]
    )]
    public function csv(Request $request): JsonResponse
    {
        $this->authorize('create', TimeEntry::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'order_id' => ['required', 'uuid', 'exists:orders,id'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $ownerId = $user->accountOwnerId();

        // Global user scope 404s orders of other accounts
        $order = Order::query()->findOrFail($request->string('order_id')->toString());

        try {
            $result = $this->importAction->execute(
                file: $request->file('file')->openFile(),
                userId: $ownerId,
                orderId: $order->id,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => __('time_tracking.csv_format_unknown')], 422);
        }

        return response()->json($result);
    }
}
