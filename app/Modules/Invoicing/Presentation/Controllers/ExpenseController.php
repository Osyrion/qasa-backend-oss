<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Contracts\ExpenseRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\ExpenseData;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Presentation\Resources\ExpenseResource;
use App\Modules\Shared\Support\Pagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

class ExpenseController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ExpenseRepositoryInterface $repository,
    ) {}

    #[OA\Get(
        path: '/api/v1/expenses',
        summary: 'List expenses',
        security: [['sanctum' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string', enum: ['office', 'travel', 'software', 'hardware', 'marketing', 'education', 'services', 'other'])),
            new OA\Parameter(name: 'currency', in: 'query', schema: new OA\Schema(type: 'string', enum: ['CZK', 'EUR', 'USD'])),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'year', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated expenses',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Expense')),
                    new OA\Property(property: 'meta', type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $expenses = $this->repository->paginate(
            perPage: Pagination::perPage($request),
            filters: $request->only(['category', 'currency', 'date_from', 'date_to', 'year']),
        );

        return ExpenseResource::collection($expenses);
    }

    #[OA\Get(
        path: '/api/v1/expenses/{expense}',
        summary: 'Show an expense',
        security: [['sanctum' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'expense', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Expense',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Expense'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Expense $expense): ExpenseResource
    {
        $this->authorize('view', $expense);

        return ExpenseResource::make($expense);
    }

    #[OA\Post(
        path: '/api/v1/expenses',
        summary: 'Create an expense',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['description', 'category', 'amount', 'currency', 'date'],
                properties: [
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'category', type: 'string', enum: ['office', 'travel', 'software', 'hardware', 'marketing', 'education', 'services', 'other']),
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Expenses'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created expense',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Expense'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = ExpenseData::validateAndCreate($request->all());

        /** @var User $user */
        $user = $request->user();

        $expense = $this->repository->create([
            'user_id' => $user->accountOwnerId(),
            'description' => $data->description,
            'category' => $data->category->value,
            'amount' => $data->amount,
            'currency' => $data->currency->value,
            'date' => $data->date,
            'note' => $data->note,
        ]);

        return ExpenseResource::make($expense)->response()->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/expenses/{expense}',
        summary: 'Update an expense',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['description', 'category', 'amount', 'currency', 'date'],
                properties: [
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'category', type: 'string', enum: ['office', 'travel', 'software', 'hardware', 'marketing', 'education', 'services', 'other']),
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'expense', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated expense',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Expense'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);

        $data = ExpenseData::validateAndCreate($request->all());
        $updated = $this->repository->update($expense, [
            'description' => $data->description,
            'category' => $data->category->value,
            'amount' => $data->amount,
            'currency' => $data->currency->value,
            'date' => $data->date,
            'note' => $data->note,
        ]);

        return ExpenseResource::make($updated)->response();
    }

    #[OA\Delete(
        path: '/api/v1/expenses/{expense}',
        summary: 'Delete an expense',
        security: [['sanctum' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'expense', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);

        $this->repository->delete($expense);

        return response()->json(null, 204);
    }
}
