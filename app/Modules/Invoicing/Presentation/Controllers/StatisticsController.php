<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Services\Statistics\HealthStatisticsService;
use App\Modules\Invoicing\Application\Services\Statistics\OverviewStatisticsService;
use App\Modules\Invoicing\Application\Services\Statistics\PartnerStatisticsService;
use App\Modules\Invoicing\Application\Services\Statistics\ReceivablesStatisticsService;
use App\Modules\Invoicing\Application\Services\Statistics\TableStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Statistics',
    description: 'BI dashboard endpoints — KPIs, aging, top partners, financial health and year/month tables'
)]
class StatisticsController extends Controller
{
    #[OA\Get(
        path: '/api/v1/statistics/overview',
        summary: 'KPI cards, period comparison and profit charts',
        description: 'All figures converted to the user\'s default currency. Cached for 5 minutes.',
        security: [['sanctum' => []]],
        tags: ['Statistics'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Overview statistics',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'currency', type: 'string'),
                                new OA\Property(
                                    property: 'kpi',
                                    properties: [
                                        new OA\Property(property: 'revenue', ref: '#/components/schemas/StatisticsKpiBlock'),
                                        new OA\Property(property: 'costs', ref: '#/components/schemas/StatisticsKpiBlock'),
                                        new OA\Property(
                                            property: 'profit',
                                            allOf: [
                                                new OA\Schema(ref: '#/components/schemas/StatisticsKpiBlock'),
                                                new OA\Schema(properties: [
                                                    new OA\Property(property: 'ytd_margin_percent', type: 'number', format: 'float', nullable: true),
                                                ]),
                                            ]
                                        ),
                                    ],
                                    type: 'object'
                                ),
                                new OA\Property(
                                    property: 'comparison',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'period', type: 'string', enum: ['this_month', 'last_month', 'rolling_12m', 'ytd', 'last_year']),
                                            new OA\Property(property: 'date_from', type: 'string', format: 'date'),
                                            new OA\Property(property: 'date_to', type: 'string', format: 'date'),
                                            new OA\Property(property: 'revenue', type: 'number', format: 'float'),
                                            new OA\Property(property: 'costs', type: 'number', format: 'float'),
                                            new OA\Property(property: 'profit', type: 'number', format: 'float'),
                                            new OA\Property(property: 'margin_percent', type: 'number', format: 'float', nullable: true),
                                        ],
                                        type: 'object'
                                    )
                                ),
                                new OA\Property(
                                    property: 'monthly_trend',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'month', type: 'string', example: '2026-07'),
                                            new OA\Property(property: 'revenue', type: 'number', format: 'float'),
                                            new OA\Property(property: 'costs', type: 'number', format: 'float'),
                                            new OA\Property(property: 'profit', type: 'number', format: 'float'),
                                        ],
                                        type: 'object'
                                    )
                                ),
                                new OA\Property(
                                    property: 'profit_chart',
                                    properties: [
                                        new OA\Property(
                                            property: 'monthly',
                                            type: 'array',
                                            items: new OA\Items(
                                                properties: [
                                                    new OA\Property(property: 'month', type: 'string', example: '2026-07'),
                                                    new OA\Property(property: 'profit', type: 'number', format: 'float'),
                                                    new OA\Property(property: 'profit_previous_year', type: 'number', format: 'float'),
                                                ],
                                                type: 'object'
                                            )
                                        ),
                                        new OA\Property(
                                            property: 'cumulative_ytd',
                                            type: 'array',
                                            items: new OA\Items(
                                                properties: [
                                                    new OA\Property(property: 'month', type: 'string', example: '2026-07'),
                                                    new OA\Property(property: 'current', type: 'number', format: 'float'),
                                                    new OA\Property(property: 'previous_year', type: 'number', format: 'float'),
                                                ],
                                                type: 'object'
                                            )
                                        ),
                                    ],
                                    type: 'object'
                                ),
                                new OA\Property(property: 'assumptions', type: 'array', items: new OA\Items(type: 'string')),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function overview(Request $request, OverviewStatisticsService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $service->getStatistics($user)]);
    }

    #[OA\Get(
        path: '/api/v1/statistics/tables',
        summary: 'Revenue/cost/profit tables by year and by month',
        security: [['sanctum' => []]],
        tags: ['Statistics'],
        parameters: [
            new OA\Parameter(
                name: 'year',
                description: 'Year for the by_month breakdown (defaults to the current year)',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 2000, maximum: 2100)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Yearly and monthly tables',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'currency', type: 'string'),
                                new OA\Property(
                                    property: 'by_year',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'year', type: 'integer'),
                                            new OA\Property(property: 'date_from', type: 'string', format: 'date'),
                                            new OA\Property(property: 'date_to', type: 'string', format: 'date'),
                                            new OA\Property(property: 'revenue', type: 'number', format: 'float'),
                                            new OA\Property(property: 'costs', type: 'number', format: 'float'),
                                            new OA\Property(property: 'profit', type: 'number', format: 'float'),
                                            new OA\Property(property: 'margin_percent', type: 'number', format: 'float', nullable: true),
                                        ],
                                        type: 'object'
                                    )
                                ),
                                new OA\Property(
                                    property: 'by_month',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'month', type: 'string', example: '2026-07'),
                                            new OA\Property(property: 'date_from', type: 'string', format: 'date'),
                                            new OA\Property(property: 'date_to', type: 'string', format: 'date'),
                                            new OA\Property(property: 'revenue', type: 'number', format: 'float'),
                                            new OA\Property(property: 'costs', type: 'number', format: 'float'),
                                            new OA\Property(property: 'profit', type: 'number', format: 'float'),
                                            new OA\Property(property: 'margin_percent', type: 'number', format: 'float', nullable: true),
                                        ],
                                        type: 'object'
                                    )
                                ),
                                new OA\Property(property: 'assumptions', type: 'array', items: new OA\Items(type: 'string')),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid year'),
        ]
    )]
    public function tables(Request $request, TableStatisticsService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $year = $request->filled('year') ? (int) $request->input('year') : null;

        return response()->json(['data' => $service->getStatistics($user, $year)]);
    }

    #[OA\Get(
        path: '/api/v1/statistics/receivables',
        summary: 'Aging buckets for open receivables and payables, per currency',
        security: [['sanctum' => []]],
        tags: ['Statistics'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Receivables and payables aging. `receivables`/`payables` are keyed by currency code, then by bucket (`not_yet_due`, `d1_30`, `d31_60`, `d61_90`, `d90_plus`), each holding `{amount: number, count: integer}`.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'as_of', type: 'string', format: 'date'),
                                new OA\Property(property: 'receivables', type: 'object', example: ['EUR' => ['not_yet_due' => ['amount' => 100.0, 'count' => 1]]]),
                                new OA\Property(property: 'payables', type: 'object', example: ['EUR' => ['not_yet_due' => ['amount' => 100.0, 'count' => 1]]]),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function receivables(Request $request, ReceivablesStatisticsService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $service->getStatistics($user)]);
    }

    #[OA\Get(
        path: '/api/v1/statistics/partners',
        summary: 'Top clients, top suppliers, and churn risk',
        security: [['sanctum' => []]],
        tags: ['Statistics'],
        parameters: [
            new OA\Parameter(
                name: 'limit',
                description: 'Max entries per currency for the top-clients/top-suppliers rankings (default 10, max 50)',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 10, minimum: 1, maximum: 50)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Partner rankings and churn risk. `top_clients`/`top_suppliers` are keyed by currency code, each holding a ranked list.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'top_clients',
                                    type: 'object',
                                    example: ['EUR' => [['client_id' => '...', 'name' => 'Acme s.r.o.', 'amount' => 1000.0, 'percent_share' => 42.5]]]
                                ),
                                new OA\Property(
                                    property: 'top_suppliers',
                                    type: 'object',
                                    example: ['EUR' => [['client_id' => '...', 'name' => 'Vendor s.r.o.', 'amount' => 500.0, 'percent_share' => 30.0]]]
                                ),
                                new OA\Property(
                                    property: 'churn_risk',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
                                            new OA\Property(property: 'name', type: 'string', nullable: true),
                                            new OA\Property(property: 'last_invoice_at', type: 'string', format: 'date'),
                                            new OA\Property(property: 'days_since_last_invoice', type: 'integer'),
                                            new OA\Property(property: 'lifetime_revenue', type: 'number', format: 'float'),
                                            new OA\Property(property: 'currency', type: 'string'),
                                        ],
                                        type: 'object'
                                    )
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid limit'),
        ]
    )]
    public function partners(Request $request, PartnerStatisticsService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $limit = $request->filled('limit') ? (int) $request->input('limit') : 10;

        return response()->json(['data' => $service->getStatistics($user, $limit)]);
    }

    #[OA\Get(
        path: '/api/v1/statistics/health',
        summary: 'DSO/DPO, payment morale, revenue concentration and working capital cycle',
        security: [['sanctum' => []]],
        tags: ['Statistics'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Financial health metrics',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'currency', type: 'string'),
                                new OA\Property(
                                    property: 'dso',
                                    properties: [
                                        new OA\Property(property: 'days', type: 'number', format: 'float', nullable: true),
                                        new OA\Property(property: 'sample_size', type: 'integer'),
                                    ],
                                    type: 'object'
                                ),
                                new OA\Property(
                                    property: 'payment_morale',
                                    properties: [
                                        new OA\Property(property: 'on_time_percent', type: 'number', format: 'float', nullable: true),
                                        new OA\Property(property: 'late_percent', type: 'number', format: 'float', nullable: true),
                                        new OA\Property(property: 'avg_days_late', type: 'number', format: 'float', nullable: true),
                                        new OA\Property(property: 'sample_size', type: 'integer'),
                                    ],
                                    type: 'object'
                                ),
                                new OA\Property(property: 'client_concentration', ref: '#/components/schemas/StatisticsConcentration'),
                                new OA\Property(
                                    property: 'dpo',
                                    properties: [
                                        new OA\Property(property: 'days', type: 'number', format: 'float', nullable: true),
                                        new OA\Property(property: 'sample_size', type: 'integer'),
                                    ],
                                    type: 'object'
                                ),
                                new OA\Property(property: 'supplier_concentration', ref: '#/components/schemas/StatisticsConcentration'),
                                new OA\Property(property: 'working_capital_cycle_days', type: 'number', format: 'float', nullable: true),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function health(Request $request, HealthStatisticsService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $service->getStatistics($user)]);
    }
}
