<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\EuSalesListRowData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementReportData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementRowData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementSummaryRowData;
use App\Modules\Invoicing\Application\Services\EuSalesListService;
use App\Modules\Invoicing\Application\Services\VatControlStatementService;
use App\Modules\Invoicing\Domain\Services\DphKh1XmlBuilder;
use App\Modules\Invoicing\Domain\Services\KvDphXmlBuilder;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'VatReports',
    description: 'Read-only VAT reporting endpoints'
)]
class VatReportController extends Controller
{
    public function __construct(
        private readonly EuSalesListService $euSalesListService,
        private readonly VatControlStatementService $vatControlStatementService,
        private readonly DphKh1XmlBuilder $dphKh1XmlBuilder,
        private readonly KvDphXmlBuilder $kvDphXmlBuilder,
    ) {}

    #[OA\Get(
        path: '/api/v1/reports/eu-sales-list',
        summary: 'EU sales list (súhrnný výkaz) basis — intra-EU reverse-charged invoices grouped by month and client VAT ID',
        security: [['sanctum' => []]],
        tags: ['VatReports'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026)),
            new OA\Parameter(name: 'quarter', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 4)),
            new OA\Parameter(name: 'month', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'EU sales list rows',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'period', type: 'string', example: '2026-01'),
                                new OA\Property(property: 'vat_id', type: 'string', example: 'DE123456789'),
                                new OA\Property(property: 'client_name', type: 'string'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float'),
                                new OA\Property(property: 'code', type: 'integer', example: 3, description: '3 = services'),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function euSalesList(Request $request): JsonResponse
    {
        $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $rows = $this->euSalesListService->forPeriod(
            userId: $user->accountOwnerId(),
            year: $request->integer('year'),
            quarter: $request->filled('quarter') ? $request->integer('quarter') : null,
            month: $request->filled('month') ? $request->integer('month') : null,
        );

        return response()->json([
            'data' => array_map(fn (EuSalesListRowData $row): array => [
                'period' => $row->period,
                'vat_id' => $row->vatId,
                'client_name' => $row->clientName,
                'amount' => $row->amount,
                'code' => $row->code,
            ], $rows),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/reports/vat-control-statement',
        summary: 'VAT control statement basis (SK: kontrolný výkaz DPH / CZ: kontrolní hlášení) — data preparation only, not a filing',
        security: [['sanctum' => []]],
        tags: ['VatReports'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['SK', 'CZ'])),
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026)),
            new OA\Parameter(name: 'quarter', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 4)),
            new OA\Parameter(name: 'month', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'VAT control statement sections'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error or the account is not a VAT payer'),
        ]
    )]
    public function vatControlStatement(Request $request): JsonResponse
    {
        $request->validate([
            'country' => ['required', 'string', 'in:SK,CZ'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $country = strtoupper((string) $request->string('country'));

        try {
            $report = $this->buildVatControlStatement($request, $user);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $xmlAssumptions = $country === 'CZ' ? $this->dphKh1XmlBuilder->assumptions() : $this->kvDphXmlBuilder->assumptions();

        return response()->json($this->serializeVatControlStatement($report, $xmlAssumptions));
    }

    #[OA\Get(
        path: '/api/v1/reports/vat-control-statement/xml',
        summary: 'VAT control statement XML draft (CZ: DPHKH1 / SK: KVDPH_2025) — draft for review before filing, not a submission-ready document',
        security: [['sanctum' => []]],
        tags: ['VatReports'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['SK', 'CZ'])),
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026)),
            new OA\Parameter(name: 'quarter', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 4)),
            new OA\Parameter(name: 'month', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'XML document', content: new OA\MediaType(mediaType: 'application/xml')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error or the account is not a VAT payer'),
        ]
    )]
    public function vatControlStatementXml(Request $request): Response|JsonResponse
    {
        $request->validate([
            'country' => ['required', 'string', 'in:SK,CZ'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $country = strtoupper((string) $request->string('country'));

        if ($country === 'SK' && ! $request->filled('month') && ! $request->filled('quarter')) {
            return response()->json(['message' => __('invoicing.vat_control_statement_period_required')], 422);
        }

        try {
            $report = $this->buildVatControlStatement($request, $user);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $body = $country === 'CZ'
            ? $this->dphKh1XmlBuilder->build($report, $user)
            : $this->kvDphXmlBuilder->build($report, $user);

        $periodSuffix = $report->month !== null ? sprintf('m%02d', $report->month) : sprintf('q%d', $report->quarter);
        $filename = sprintf('%s-%d-%s.xml', $country === 'CZ' ? 'dphkh1' : 'kvdph', $report->year, $periodSuffix);

        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function buildVatControlStatement(Request $request, User $user): VatControlStatementReportData
    {
        if (! $user->vat_status->isVatPayer()) {
            throw DomainException::because(__('invoicing.vat_report_payer_only'));
        }

        return $this->vatControlStatementService->forPeriod(
            userId: $user->accountOwnerId(),
            country: strtoupper((string) $request->string('country')),
            year: $request->integer('year'),
            quarter: $request->filled('quarter') ? $request->integer('quarter') : null,
            month: $request->filled('month') ? $request->integer('month') : null,
        );
    }

    /**
     * @param  list<string>  $extraAssumptions  caveats specific to the XML draft — surfaced here too so a JSON
     *                                          consumer sees them before ever downloading the XML
     * @return array<string, mixed>
     */
    private function serializeVatControlStatement(VatControlStatementReportData $report, array $extraAssumptions = []): array
    {
        $mapRow = static fn (VatControlStatementRowData $row): array => [
            'document_number' => $row->documentNumber,
            'date' => $row->date,
            'partner_name' => $row->partnerName,
            'partner_tax_id' => $row->partnerTaxId,
            'rate' => $row->rate,
            'base' => $row->base,
            'vat' => $row->vat,
            'related_document_number' => $row->relatedDocumentNumber,
        ];

        $mapSummaryRow = static fn (VatControlStatementSummaryRowData $row): array => [
            'rate' => $row->rate,
            'base' => $row->base,
            'vat' => $row->vat,
        ];

        return [
            'country' => $report->country,
            'year' => $report->year,
            'quarter' => $report->quarter,
            'month' => $report->month,
            'sections' => array_map(
                fn (array $rows): array => array_map($mapRow, $rows),
                $report->rowSections,
            ),
            'summary_sections' => array_map(
                fn (array $rows): array => array_map($mapSummaryRow, $rows),
                $report->summarySections,
            ),
            'assumptions' => array_values(array_unique([...$report->assumptions, ...$extraAssumptions])),
        ];
    }
}
