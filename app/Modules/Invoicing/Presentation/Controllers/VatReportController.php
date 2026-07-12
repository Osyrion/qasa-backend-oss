<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\EuSalesListRowData;
use App\Modules\Invoicing\Application\Services\EuSalesListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
