<?php

declare(strict_types=1);

use App\Modules\Shared\Support\Search;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A functional index only serves a query that repeats its expression
 * character for character. Nothing fails loudly when the two drift apart —
 * search keeps returning the right rows, it just quietly stops using the
 * index — so this asserts the pairing directly.
 *
 * enable_seqscan is turned off because the test tables are tiny and a
 * sequential scan would win on cost alone. The question here is not which
 * plan Postgres prefers, it is whether the index is usable for this
 * predicate at all: a mismatched expression still reports Seq Scan even
 * with the setting off.
 *
 * The columns are spelled out inline rather than driven from a dataset
 * because Search::unaccentedLike() takes a literal-string — the same
 * constraint that stops a request-supplied column reaching raw SQL.
 */
function planFor(Builder $query): string
{
    // Planned on the owner connection, which Row Level Security does not
    // apply to. Under a policy the tenant predicate is the better access path
    // — Postgres reaches for the user_id index and applies the trigram match
    // as a filter — which is the right plan but tells us nothing about the
    // thing this test exists for: whether the query expression still matches
    // the index expression.
    // Session-scoped SET, not SET LOCAL: the test transaction belongs to the
    // default connection, and SET LOCAL outside a transaction does nothing.
    $connection = DB::connection('pgsql_system');
    $connection->statement('SET enable_seqscan = off');

    try {
        /** @var list<object{'QUERY PLAN': string}> $rows */
        $rows = $connection->select('EXPLAIN '.$query->toSql(), $query->getBindings());
    } finally {
        $connection->statement('RESET enable_seqscan');
    }

    return implode("\n", array_map(
        static fn (object $row): string => $row->{'QUERY PLAN'},
        $rows,
    ));
}

/**
 * @param  literal-string  $sql
 */
function planForRaw(string $table, string $sql): string
{
    return planFor(DB::table($table)->whereRaw($sql, [Search::term('kaviaren')]));
}

it('serves the diacritics-insensitive client search from trigram indexes', function (): void {
    expect(planForRaw('clients', Search::unaccentedLike('name')))
        ->toContain('clients_name_unaccent_trgm_idx')
        ->and(planForRaw('clients', Search::unaccentedLike('surname')))
        ->toContain('clients_surname_unaccent_trgm_idx')
        ->and(planForRaw('clients', Search::unaccentedLike('company_name')))
        ->toContain('clients_company_name_unaccent_trgm_idx');
});

it('serves the diacritics-insensitive order search from a trigram index', function (): void {
    expect(planForRaw('orders', Search::unaccentedLike('name')))
        ->toContain('orders_name_unaccent_trgm_idx');
});

it('serves the plain ILIKE search from a trigram index', function (string $column, string $index): void {
    $query = DB::table('clients')->where($column, 'ilike', Search::term('example'));

    expect(planFor($query))->toContain($index);
})->with([
    'clients.email' => ['email', 'clients_email_trgm_idx'],
    'clients.ico' => ['ico', 'clients_ico_trgm_idx'],
]);

it('serves the full-text search from GIN indexes', function (): void {
    $term = Search::fullTextTerm('rekonstrukcia') ?? '';

    expect(planFor(DB::table('invoices')->whereRaw(Search::fullTextMatch('note', 'note_above'), [$term])))
        ->toContain('invoices_note_fts_idx')
        ->and(planFor(DB::table('invoice_items')->whereRaw(Search::fullTextMatch('description'), [$term])))
        ->toContain('invoice_items_description_fts_idx')
        ->and(planFor(DB::table('invoice_inbox_items')->whereRaw(Search::fullTextMatch('ocr_text'), [$term])))
        ->toContain('invoice_inbox_items_ocr_text_fts_idx');
});
