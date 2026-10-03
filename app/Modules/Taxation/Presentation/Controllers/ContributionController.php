<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Support\Pagination;
use App\Modules\Taxation\Application\DTOs\ContributionPaymentData;
use App\Modules\Taxation\Domain\Enums\ContributionType;
use App\Modules\Taxation\Domain\Models\ContributionPayment;
use App\Modules\Taxation\Presentation\Resources\ContributionPaymentResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

class ContributionController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/v1/contributions',
        summary: 'List contribution payments (social/health/income tax advances)',
        security: [['sanctum' => []]],
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['social', 'health', 'income_tax_advance'])),
            new OA\Parameter(name: 'year', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContributionPayment')),
                    new OA\Property(property: 'meta', type: 'object'),
                ])
            ),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ContributionPayment::class);

        $query = ContributionPayment::query()->orderByDesc('paid_at');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('year')) {
            $query->where('period_year', $request->integer('year'));
        }

        $payments = Pagination::of($query, $request);

        return ContributionPaymentResource::collection($payments);
    }

    #[OA\Get(
        path: '/api/v1/contributions/summary',
        summary: 'Yearly summary of contribution payments, totalled per type',
        security: [['sanctum' => []]],
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Per-type totals for the year',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'year', type: 'integer'),
                    new OA\Property(
                        property: 'totals',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'social', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'number', format: 'float')),
                            new OA\Property(property: 'health', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'number', format: 'float')),
                            new OA\Property(property: 'income_tax_advance', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'number', format: 'float')),
                        ]
                    ),
                ])
            ),
        ]
    )]
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ContributionPayment::class);

        $year = $request->integer('year', (int) now()->format('Y'));

        $rows = ContributionPayment::query()
            ->toBase()
            ->selectRaw('type, currency, SUM(amount) as total')
            ->where('period_year', $year)
            ->groupBy('type', 'currency')
            ->get();

        $summary = [];

        foreach (ContributionType::cases() as $type) {
            $summary[$type->value] = [];
        }

        foreach ($rows as $row) {
            $type = (string) $row->type;
            $currency = (string) $row->currency;
            $summary[$type][$currency] = (float) $row->total;
        }

        return response()->json(['year' => $year, 'totals' => $summary]);
    }

    #[OA\Get(
        path: '/api/v1/contributions/{contribution}',
        summary: 'Show a contribution payment',
        security: [['sanctum' => []]],
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'contribution', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Contribution payment',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/ContributionPayment'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(ContributionPayment $contribution): ContributionPaymentResource
    {
        $this->authorize('view', $contribution);

        return ContributionPaymentResource::make($contribution);
    }

    #[OA\Post(
        path: '/api/v1/contributions',
        summary: 'Record a paid social/health contribution or tax advance',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'period_year', 'amount', 'currency', 'paid_at'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['social', 'health', 'income_tax_advance']),
                    new OA\Property(property: 'period_year', type: 'integer', example: 2026),
                    new OA\Property(property: 'period_month', type: 'integer', nullable: true, example: 3),
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'paid_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Taxation'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/ContributionPayment'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ContributionPayment::class);

        $data = ContributionPaymentData::validateAndCreate($request->all());

        /** @var Account $user */
        $user = $request->user();

        $payment = ContributionPayment::query()->create([
            'user_id' => $user->accountOwnerId(),
            'type' => $data->type->value,
            'period_year' => $data->period_year,
            'period_month' => $data->period_month,
            'amount' => $data->amount,
            'currency' => $data->currency->value,
            'paid_at' => $data->paid_at,
            'note' => $data->note,
        ]);

        return ContributionPaymentResource::make($payment)->response()->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/contributions/{contribution}',
        summary: 'Update a contribution payment',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'period_year', 'amount', 'currency', 'paid_at'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['social', 'health', 'income_tax_advance']),
                    new OA\Property(property: 'period_year', type: 'integer', example: 2026),
                    new OA\Property(property: 'period_month', type: 'integer', nullable: true, example: 3),
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'paid_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'contribution', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/ContributionPayment'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[OA\Patch(
        path: '/api/v1/contributions/{contribution}',
        summary: 'Update a contribution payment',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'period_year', 'amount', 'currency', 'paid_at'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['social', 'health', 'income_tax_advance']),
                    new OA\Property(property: 'period_year', type: 'integer', example: 2026),
                    new OA\Property(property: 'period_month', type: 'integer', nullable: true, example: 3),
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'paid_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'contribution', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/ContributionPayment'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, ContributionPayment $contribution): JsonResponse
    {
        $this->authorize('update', $contribution);

        $data = ContributionPaymentData::validateAndCreate($request->all());

        $contribution->update([
            'type' => $data->type->value,
            'period_year' => $data->period_year,
            'period_month' => $data->period_month,
            'amount' => $data->amount,
            'currency' => $data->currency->value,
            'paid_at' => $data->paid_at,
            'note' => $data->note,
        ]);

        return ContributionPaymentResource::make($contribution)->response();
    }

    #[OA\Delete(
        path: '/api/v1/contributions/{contribution}',
        summary: 'Delete a contribution payment',
        security: [['sanctum' => []]],
        tags: ['Taxation'],
        parameters: [
            new OA\Parameter(name: 'contribution', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(ContributionPayment $contribution): JsonResponse
    {
        $this->authorize('delete', $contribution);

        $contribution->delete();

        return response()->json(null, 204);
    }
}
