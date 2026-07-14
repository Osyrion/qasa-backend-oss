<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Controllers;

use App\Modules\Shared\Domain\Models\ActivityLog;
use App\Modules\Shared\Presentation\Resources\ActivityLogResource;
use App\Modules\Shared\Support\Pagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

class ActivityController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/v1/activity',
        summary: 'List activity log entries for the account',
        security: [['sanctum' => []]],
        tags: ['Activity'],
        parameters: [
            new OA\Parameter(name: 'subject_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'subject_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Activity log entries, newest first',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ActivityLog')),
                    new OA\Property(property: 'meta', type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ActivityLog::class);

        $entries = ActivityLog::query()
            ->when($request->filled('subject_type'), fn ($query) => $query->where('subject_type', $request->string('subject_type')->toString()))
            ->when($request->filled('subject_id'), fn ($query) => $query->where('subject_id', $request->string('subject_id')->toString()))
            ->orderByDesc('created_at')
            ->paginate(Pagination::perPage($request));

        return ActivityLogResource::collection($entries);
    }
}
