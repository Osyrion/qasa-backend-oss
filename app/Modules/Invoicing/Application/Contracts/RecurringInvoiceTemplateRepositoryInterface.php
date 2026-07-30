<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface RecurringInvoiceTemplateRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, RecurringInvoiceTemplate>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator;

    public function findByIdOrFail(string $id): RecurringInvoiceTemplate;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): RecurringInvoiceTemplate;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(RecurringInvoiceTemplate $template, array $data): RecurringInvoiceTemplate;

    public function delete(RecurringInvoiceTemplate $template): void;

    /**
     * Active templates due on or before $today, across all accounts
     * (scheduler runs unauthenticated).
     *
     * @return Collection<int, RecurringInvoiceTemplate>
     */
    public function dueForGeneration(CarbonImmutable $today): Collection;
}
