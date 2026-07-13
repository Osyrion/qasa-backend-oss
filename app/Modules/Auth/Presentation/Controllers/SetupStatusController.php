<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Controllers;

use App\Modules\Auth\Application\Services\SetupStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

class SetupStatusController extends Controller
{
    public function __construct(
        private readonly SetupStatusService $setupStatusService,
    ) {}

    #[OA\Get(
        path: '/api/v1/profile/setup-status',
        summary: 'Onboarding checklist for the account',
        security: [['sanctum' => []]],
        tags: ['Profile'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Setup checklist',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'items',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'key', type: 'string', enum: [
                                                'billing_identity', 'vat_status', 'bank_account',
                                                'invoice_numbering', 'logo', 'first_client', 'first_invoice',
                                            ]),
                                            new OA\Property(property: 'done', type: 'boolean'),
                                            new OA\Property(property: 'optional', type: 'boolean'),
                                        ]
                                    )
                                ),
                                new OA\Property(property: 'completed', type: 'boolean', description: 'True once every non-optional item is done'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $status = $this->setupStatusService->getStatus($request->user());

        return response()->json(['data' => $status]);
    }
}
