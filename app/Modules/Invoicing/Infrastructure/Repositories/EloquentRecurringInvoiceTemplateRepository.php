<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Repositories;

use App\Modules\Invoicing\Application\Contracts\RecurringInvoiceTemplateRepositoryInterface;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentRecurringInvoiceTemplateRepository implements RecurringInvoiceTemplateRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, RecurringInvoiceTemplate>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = RecurringInvoiceTemplate::query()->with(['client', 'items']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        return $query->orderBy('next_run_date')->paginate($perPage);
    }

    public function findByIdOrFail(string $id): RecurringInvoiceTemplate
    {
        /** @var RecurringInvoiceTemplate */
        return RecurringInvoiceTemplate::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): RecurringInvoiceTemplate
    {
        /** @var RecurringInvoiceTemplate */
        return RecurringInvoiceTemplate::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(RecurringInvoiceTemplate $template, array $data): RecurringInvoiceTemplate
    {
        $template->update($data);

        return $template->fresh() ?? $template;
    }

    public function delete(RecurringInvoiceTemplate $template): void
    {
        $template->delete();
    }

    public function dueForGeneration(CarbonImmutable $today): Collection
    {
        return RecurringInvoiceTemplate::withoutGlobalScope('user')
            ->dueForGeneration($today)
            ->with(['items', 'client', 'user'])
            ->orderBy('next_run_date')
            ->get();
    }
}
