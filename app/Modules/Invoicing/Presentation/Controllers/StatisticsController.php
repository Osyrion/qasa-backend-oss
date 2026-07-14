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
            new OA\Response(response: 200, description: 'Overview statistics'),
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
            new OA\Response(response: 200, description: 'Yearly and monthly tables'),
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
            new OA\Response(response: 200, description: 'Receivables and payables aging'),
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
            new OA\Response(response: 200, description: 'Partner rankings and churn risk'),
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
            new OA\Response(response: 200, description: 'Financial health metrics'),
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
