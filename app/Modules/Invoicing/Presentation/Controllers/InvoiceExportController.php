<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\InvoiceExportData;
use App\Modules\Invoicing\Application\Services\InvoiceCsvBuilder;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Services\PohodaXmlBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Invoice Export',
    description: 'Bulk export of issued invoices for accounting handoff'
)]
class InvoiceExportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly InvoiceRepositoryInterface $repository,
        private readonly PohodaXmlBuilder $pohodaBuilder,
        private readonly InvoiceCsvBuilder $csvBuilder,
    ) {}

    #[OA\Get(
        path: '/api/v1/invoices/export/pohoda',
        summary: 'Export issued invoices as a Stormware Pohoda dataPack XML',
        security: [['sanctum' => []]],
        tags: ['Invoice Export'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'period_basis', in: 'query', schema: new OA\Schema(type: 'string', enum: ['issue', 'tax'])),
            new OA\Parameter(
                name: 'types[]',
                in: 'query',
                schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', enum: ['invoice', 'credit_note', 'storno']))
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Pohoda dataPack XML file download',
                content: new OA\MediaType(mediaType: 'application/xml')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function pohoda(Request $request): Response
    {
        $this->authorize('viewAny', Invoice::class);

        $filter = InvoiceExportData::validateAndCreate($request->all());
        $invoices = $this->repository->forExport($filter);
        $body = $this->pohodaBuilder->build($invoices);

        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($filter, 'pohoda', 'xml').'"',
        ]);
    }

    #[OA\Get(
        path: '/api/v1/invoices/export/csv',
        summary: 'Export issued invoices as CSV',
        security: [['sanctum' => []]],
        tags: ['Invoice Export'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'period_basis', in: 'query', schema: new OA\Schema(type: 'string', enum: ['issue', 'tax'])),
            new OA\Parameter(
                name: 'types[]',
                in: 'query',
                schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', enum: ['invoice', 'credit_note', 'storno']))
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'CSV file download',
                content: new OA\MediaType(mediaType: 'text/csv')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function csv(Request $request): Response
    {
        $this->authorize('viewAny', Invoice::class);

        $filter = InvoiceExportData::validateAndCreate($request->all());
        $invoices = $this->repository->forExport($filter);
        $body = $this->csvBuilder->build($invoices);

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($filter, 'faktury', 'csv').'"',
        ]);
    }

    /**
     * Filenames are built from parsed dates rather than the raw request
     * strings, so no user-controlled value ever reaches the header.
     */
    private function filename(InvoiceExportData $filter, string $prefix, string $extension): string
    {
        return sprintf(
            '%s_%s_%s.%s',
            $prefix,
            CarbonImmutable::parse($filter->date_from)->format('Y-m-d'),
            CarbonImmutable::parse($filter->date_to)->format('Y-m-d'),
            $extension,
        );
    }
}
