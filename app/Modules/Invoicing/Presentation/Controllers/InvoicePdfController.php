<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Invoicing\Application\Services\InvoicePdfService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Invoice PDF',
    description: 'Invoice PDF generation endpoints'
)]
class InvoicePdfController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly InvoicePdfService $pdfService,
    ) {}

    #[OA\Get(
        path: '/api/v1/invoices/{id}/pdf/download',
        summary: 'Download invoice PDF',
        security: [['sanctum' => []]],
        tags: ['Invoice PDF'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'PDF file download',
                content: new OA\MediaType(mediaType: 'application/pdf')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice not found'),
        ]
    )]
    public function download(Request $request, Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);

        $pdf = $this->pdfService->generate($invoice);
        $filename = $this->pdfService->filename($invoice);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Length' => strlen($pdf),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/invoices/{id}/pdf/preview',
        summary: 'Preview invoice PDF in browser',
        security: [['sanctum' => []]],
        tags: ['Invoice PDF'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'PDF preview',
                content: new OA\MediaType(mediaType: 'application/pdf')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice not found'),
        ]
    )]
    public function preview(Request $request, Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);

        $pdf = $this->pdfService->generate($invoice);
        $filename = $this->pdfService->filename($invoice);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
}
