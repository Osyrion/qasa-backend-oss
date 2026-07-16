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

#[OA\Tag(
    name: 'Expenses',
    description: 'Business expense tracking endpoints'
)]
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
            new OA\Parameter(
                name: 'per_page',
                description: 'Items per page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 20)
            ),
            new OA\Parameter(
                name: 'category',
                description: 'Filter by category',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['office', 'travel', 'software', 'hardware', 'marketing', 'education', 'services', 'other'])
            ),
            new OA\Parameter(
                name: 'currency',
                description: 'Filter by currency',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['CZK', 'EUR', 'USD'])
            ),
            new OA\Parameter(
                name: 'date_from',
                description: 'Filter from date',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(
                name: 'date_to',
                description: 'Filter to date',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(
                name: 'year',
                description: 'Filter by year',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of expenses',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Expense')),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
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
        summary: 'Get expense details',
        security: [['sanctum' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'expense', description: 'Expense ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Expense details',
                content: new OA\JsonContent(ref: '#/components/schemas/Expense')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Expense not found'),
        ]
    )]
    public function show(Expense $expense): ExpenseResource
    {
        $this->authorize('view', $expense);

        return ExpenseResource::make($expense);
    }

    #[OA\Post(
        path: '/api/v1/expenses',
        summary: 'Create expense',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['description', 'category', 'amount', 'currency', 'date'],
                properties: [
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'category', type: 'string', enum: ['office', 'travel', 'software', 'hardware', 'marketing', 'education', 'services', 'other']),
                    new OA\Property(property: 'amount', type: 'number', format: 'float', minimum: 0.01),
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
                description: 'Expense created',
                content: new OA\JsonContent(ref: '#/components/schemas/Expense')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = ExpenseData::validateAndCreate($request->all());
        $expense = $this->repository->create([
            'user_id' => $user->accountOwnerId(),
            'description' => $data->description,
            'category' => $data->category->value,
            'amount' => $data->amount,
            'currency' => $data->currency->value,
            'date' => $data->date,
            'note' => $data->note,
        ]);

        return response()->json(ExpenseResource::make($expense), 201);
    }

    #[OA\Put(
        path: '/api/v1/expenses/{expense}',
        summary: 'Update expense',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['description', 'category', 'amount', 'currency', 'date'],
                properties: [
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'category', type: 'string', enum: ['office', 'travel', 'software', 'hardware', 'marketing', 'education', 'services', 'other']),
                    new OA\Property(property: 'amount', type: 'number', format: 'float', minimum: 0.01),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'expense', description: 'Expense ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Expense updated',
                content: new OA\JsonContent(ref: '#/components/schemas/Expense')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Expense not found'),
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

        return response()->json(ExpenseResource::make($updated));
    }

    #[OA\Delete(
        path: '/api/v1/expenses/{expense}',
        summary: 'Delete expense',
        security: [['sanctum' => []]],
        tags: ['Expenses'],
        parameters: [
            new OA\Parameter(name: 'expense', description: 'Expense ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Expense deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Expense not found'),
        ]
    )]
    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);

        // Soft delete only — the attachment file stays on disk so a restore
        // (SoftDeletes) still has its receipt/invoice document.
        $this->repository->delete($expense);

        return response()->json(null, 204);
    }
}
