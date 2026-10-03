<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Support\Pagination;

/**
 * Offset pagination is only defined under a total order, and almost none of
 * the columns these lists sort by are unique: issued_at and expenses.date are
 * `date`, and every created_at in the schema is timestamp(0). A page of rows
 * written in one batch — a recurring-invoice run, an import — ties outright,
 * and Postgres does not promise an order for tied rows across two LIMIT/OFFSET
 * queries. The reader sees one row twice and never sees another.
 *
 * tests/Architecture/StablePaginationTest.php is the other half: that nothing
 * paginates without coming through here.
 */
it('appends the primary key to a query that lacks a unique sort', function (): void {
    $query = Invoice::query()->orderBy('issued_at', 'desc');

    $reflection = new ReflectionMethod(Pagination::class, 'withTotalOrder');
    $ordered = $reflection->invoke(null, $query);

    $orders = $ordered->getQuery()->orders ?? [];

    expect($orders)->toHaveCount(2)
        ->and($orders[1]['column'])->toBe('invoices.id')
        // Direction follows the leading sort: a newest-first list should break
        // its ties newest-first too, not reverse inside each group.
        ->and($orders[1]['direction'])->toBe('desc');
});

it('leaves a query that is already ordered by its key alone', function (): void {
    $query = Invoice::query()->orderBy('id');

    $reflection = new ReflectionMethod(Pagination::class, 'withTotalOrder');
    $orders = $reflection->invoke(null, $query)->getQuery()->orders ?? [];

    expect($orders)->toHaveCount(1);
});
