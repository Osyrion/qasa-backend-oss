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

class ExpenseController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ExpenseRepositoryInterface $repository,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $expenses = $this->repository->paginate(
            perPage: Pagination::perPage($request),
            filters: $request->only(['category', 'currency', 'date_from', 'date_to', 'year']),
        );

        return ExpenseResource::collection($expenses);
    }

    public function show(Expense $expense): ExpenseResource
    {
        $this->authorize('view', $expense);

        return ExpenseResource::make($expense);
    }

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

    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);

        // Soft delete only — the attachment file stays on disk so a restore
        // (SoftDeletes) still has its receipt/invoice document.
        $this->repository->delete($expense);

        return response()->json(null, 204);
    }
}
