<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Presentation\Controllers;

use App\Modules\Shared\Support\Pagination;
use App\Modules\TimeTracking\Application\Contracts\ExpenseRepositoryInterface;
use App\Modules\TimeTracking\Application\DTOs\ExpenseData;
use App\Modules\TimeTracking\Domain\Models\Expense;
use App\Modules\TimeTracking\Presentation\Resources\ExpenseResource;
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
        $data = ExpenseData::validateAndCreate($request->all());
        $expense = $this->repository->create([
            'user_id' => $request->user()->accountOwnerId(),
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
