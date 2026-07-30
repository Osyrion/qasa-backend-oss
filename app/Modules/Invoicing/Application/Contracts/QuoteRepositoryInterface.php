<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Services\InvoiceNumberMask;
use Illuminate\Pagination\LengthAwarePaginator;

interface QuoteRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Quote>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator;

    public function findById(string $id): ?Quote;

    public function findByIdOrFail(string $id): Quote;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Quote;

    public function delete(Quote $quote): void;

    /**
     * Generate the next quote number for a user, formatted by $mask. $start
     * is the lower bound of the sequence; it never lowers a sequence
     * already in use.
     */
    public function nextQuoteNumber(string $userId, InvoiceNumberMask $mask, int $start = 1): string;
}
