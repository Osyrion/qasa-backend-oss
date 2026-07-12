<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Presentation\Controllers;

use App\Modules\Calendar\Application\Contracts\EventRepositoryInterface;
use App\Modules\Calendar\Application\DTOs\EventRangeData;
use App\Modules\Calendar\Application\Services\EventCsvBuilder;
use App\Modules\Calendar\Application\Services\IcsBuilder;
use App\Modules\Calendar\Domain\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Calendar Export', description: 'Bulk export of calendar events')]
class EventExportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly EventRepositoryInterface $repository,
        private readonly EventCsvBuilder $csvBuilder,
        private readonly IcsBuilder $icsBuilder,
    ) {}

    #[OA\Get(
        path: '/api/v1/events/export/csv',
        summary: 'Export events as CSV',
        security: [['sanctum' => []]],
        tags: ['Calendar Export'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file download', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function csv(Request $request): Response
    {
        $this->authorize('viewAny', Event::class);

        $filter = EventRangeData::validateAndCreate($request->all());
        $events = $this->repository->forExport(
            $filter->from !== null ? Carbon::parse($filter->from) : null,
            $filter->to !== null ? Carbon::parse($filter->to) : null,
        );
        $body = $this->csvBuilder->build($events);

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($filter, 'csv').'"',
        ]);
    }

    #[OA\Get(
        path: '/api/v1/events/export/ics',
        summary: 'Export events as an ICS (iCalendar) file',
        security: [['sanctum' => []]],
        tags: ['Calendar Export'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'ICS file download', content: new OA\MediaType(mediaType: 'text/calendar')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function ics(Request $request): Response
    {
        $this->authorize('viewAny', Event::class);

        $filter = EventRangeData::validateAndCreate($request->all());
        $events = $this->repository->forExport(
            $filter->from !== null ? Carbon::parse($filter->from) : null,
            $filter->to !== null ? Carbon::parse($filter->to) : null,
        );
        $body = $this->icsBuilder->build($events);

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($filter, 'ics').'"',
        ]);
    }

    /**
     * Filenames are built from parsed dates rather than the raw request
     * strings, so no user-controlled value ever reaches the header.
     */
    private function filename(EventRangeData $filter, string $extension): string
    {
        return sprintf(
            'events_%s_%s.%s',
            $filter->from !== null ? CarbonImmutable::parse($filter->from)->format('Y-m-d') : 'all',
            $filter->to !== null ? CarbonImmutable::parse($filter->to)->format('Y-m-d') : 'all',
            $extension,
        );
    }
}
