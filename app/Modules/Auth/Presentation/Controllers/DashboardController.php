<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Controllers;

use App\Modules\Auth\Application\Services\DashboardService;
use App\Modules\Auth\Domain\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Dashboard',
    description: 'Home-screen summary stats for the authenticated account'
)]
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    #[OA\Get(
        path: '/api/v1/dashboard',
        summary: 'Own-account summary stats: clients, orders, invoices, twelve-month income trend',
        description: 'Premium modules may contribute additional top-level keys (see DashboardStatsContributor).',
        security: [['sanctum' => []]],
        tags: ['Dashboard'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dashboard stats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'clients', properties: [
                            new OA\Property(property: 'total', type: 'integer'),
                        ], type: 'object'),
                        new OA\Property(property: 'orders', properties: [
                            new OA\Property(property: 'total', type: 'integer'),
                            new OA\Property(property: 'active', type: 'integer'),
                            new OA\Property(property: 'completed', type: 'integer'),
                            new OA\Property(property: 'billable', type: 'integer'),
                        ], type: 'object'),
                        new OA\Property(property: 'invoices', properties: [
                            new OA\Property(property: 'total', type: 'integer'),
                            new OA\Property(property: 'draft', type: 'integer'),
                            new OA\Property(property: 'sent', type: 'integer'),
                            new OA\Property(property: 'paid', type: 'integer'),
                            new OA\Property(property: 'revenue_paid', type: 'number', format: 'float'),
                            new OA\Property(property: 'revenue_pending', type: 'number', format: 'float'),
                            new OA\Property(property: 'volume', properties: [
                                new OA\Property(property: 'month', type: 'number', format: 'float'),
                                new OA\Property(property: 'quarter', type: 'number', format: 'float'),
                                new OA\Property(property: 'year', type: 'number', format: 'float'),
                            ], type: 'object'),
                            new OA\Property(property: 'overdue', properties: [
                                new OA\Property(property: 'count', type: 'integer'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float'),
                            ], type: 'object'),
                        ], type: 'object'),
                        new OA\Property(
                            property: 'income_trend',
                            type: 'array',
                            items: new OA\Items(properties: [
                                new OA\Property(property: 'month', type: 'string', example: '2026-07'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float'),
                            ])
                        ),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $stats = $this->dashboardService->getStats($user);

        return response()->json(['data' => $stats]);
    }
}
