<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Invoicing\Application\Actions\SyncWorkReportLinesAction;
use App\Modules\Invoicing\Application\DTOs\WorkReportLineData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Presentation\Resources\WorkReportLineResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;
use Throwable;

class WorkReportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SyncWorkReportLinesAction $syncAction,
    ) {}

    #[OA\Get(
        path: '/api/v1/invoices/{invoice}/work-report',
        summary: 'List work report lines (Výkaz víceprací)',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Work report lines',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkReportLine'))
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice not found'),
        ]
    )]
    public function index(Invoice $invoice): AnonymousResourceCollection
    {
        $this->authorize('view', $invoice);

        return WorkReportLineResource::collection($invoice->workReportLines()->get());
    }

    /**
     * @throws Throwable
     */
    #[OA\Put(
        path: '/api/v1/invoices/{invoice}/work-report',
        summary: 'Replace work report lines',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['lines'],
                properties: [
                    new OA\Property(
                        property: 'lines',
                        type: 'array',
                        items: new OA\Items(
                            required: ['work_date', 'description', 'hours'],
                            properties: [
                                new OA\Property(property: 'work_date', type: 'string', format: 'date'),
                                new OA\Property(property: 'description', type: 'string', maxLength: 255),
                                new OA\Property(property: 'hours', type: 'number', format: 'float'),
                            ]
                        )
                    ),
                ]
            )
        ),
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Replaced lines',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkReportLine'))
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice not found'),
            new OA\Response(response: 422, description: 'Validation error or invoice not editable'),
        ]
    )]
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        $request->validate(WorkReportLineData::bulkRules());

        $lines = array_values(array_map(
            static fn (array $line): WorkReportLineData => new WorkReportLineData(
                work_date: (string) $line['work_date'],
                description: (string) $line['description'],
                hours: (float) $line['hours'],
            ),
            (array) $request->input('lines', []),
        ));

        $replaced = $this->syncAction->execute($invoice, $lines);

        return response()->json(WorkReportLineResource::collection($replaced));
    }
}
