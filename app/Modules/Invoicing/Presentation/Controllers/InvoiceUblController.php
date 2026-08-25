<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Invoicing\Application\Contracts\UblInvoiceBuilderInterface;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Support\ContentDisposition;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

/**
 * The invoice as a structured e-invoice rather than a rendered page.
 *
 * Not behind a plan feature and not in the premium Accounting module: this
 * is the document's own legal format, and once structured e-invoicing is
 * mandatory an account without it cannot invoice at all.
 */
#[OA\Tag(name: 'Invoice e-invoice', description: 'UBL 2.1 / EN 16931 export')]
class InvoiceUblController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly UblInvoiceBuilderInterface $builder,
    ) {}

    #[OA\Get(
        path: '/api/v1/invoices/{id}/export/ubl',
        summary: 'Download the invoice as a UBL 2.1 (EN 16931) e-invoice',
        security: [['sanctum' => []]],
        tags: ['Invoice e-invoice'],
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
            new OA\Response(response: 200, description: 'UBL XML', content: new OA\MediaType(mediaType: 'application/xml')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Document type has no UBL equivalent (proforma, storno)'),
            new OA\Response(response: 404, description: 'Invoice not found'),
        ]
    )]
    public function export(Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);

        $xml = $this->builder->build($invoice->id);
        $filename = ($invoice->invoice_number ?? $invoice->id).'.xml';

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => ContentDisposition::attachment($filename),
        ]);
    }
}
