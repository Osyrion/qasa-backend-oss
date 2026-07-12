<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Calendar\Application\Actions\ImportEventsCsvAction;
use App\Modules\Calendar\Application\Actions\ImportEventsIcsAction;
use App\Modules\Calendar\Domain\Models\Event;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Calendar Import', description: 'Bulk-import events from CSV or ICS files')]
class EventImportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ImportEventsCsvAction $csvAction,
        private readonly ImportEventsIcsAction $icsAction,
    ) {}

    #[OA\Post(
        path: '/api/v1/events/import/csv',
        summary: 'Import events from a CSV file',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [new OA\Property(property: 'file', type: 'string', format: 'binary')],
                )
            )
        ),
        tags: ['Calendar Import'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Import summary',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'created', type: 'integer'),
                        new OA\Property(property: 'skipped', type: 'integer'),
                        new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function csv(Request $request): JsonResponse
    {
        $this->authorize('create', Event::class);

        $request->validate(['file' => ['required', 'file', 'max:5120']]);

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->csvAction->execute(
                $request->file('file')->openFile(),
                $user->accountOwnerId(),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    #[OA\Post(
        path: '/api/v1/events/import/ics',
        summary: 'Import events from an ICS (iCalendar) file',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [new OA\Property(property: 'file', type: 'string', format: 'binary')],
                )
            )
        ),
        tags: ['Calendar Import'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Import summary',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'created', type: 'integer'),
                        new OA\Property(property: 'skipped', type: 'integer'),
                        new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function ics(Request $request): JsonResponse
    {
        $this->authorize('create', Event::class);

        $request->validate(['file' => ['required', 'file', 'max:5120']]);

        /** @var User $user */
        $user = $request->user();

        $content = $request->file('file')->get();

        if ($content === false) {
            return response()->json(['message' => __('calendar.import.unsupported_format')], 422);
        }

        $result = $this->icsAction->execute($content, $user->accountOwnerId());

        return response()->json($result);
    }
}
