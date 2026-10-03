<?php

declare(strict_types=1);

namespace App\Modules\Shared\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Columns a listing must not drag along.
 *
 * `select *` is free right up until a column holds a document. OCR text runs
 * to tens of kilobytes a row, and Postgres keeps it out of the main tuple in
 * TOAST — but only until something reads the column, and hydrating a model
 * reads all of them. A page of twenty scanned invoices then costs a megabyte
 * of detoasting and PHP memory to render a listing that shows a file name, a
 * size, and a boolean.
 *
 * Naming what to *leave out* rather than listing what to select is the point:
 * an explicit column list is a second copy of the schema that silently stops
 * matching the day a column is added, and the listing quietly loses a field.
 * The set is read from the schema at runtime instead, cached per table for the
 * life of the request.
 *
 * This trims a read, it does not hide a column: `$model->refresh()` or a fresh
 * query still returns everything, and Eloquent only writes attributes it has,
 * so saving a trimmed model does not blank the deferred ones.
 */
trait HasDeferredColumns
{
    /** @var array<string, list<string>> */
    private static array $selectableColumns = [];

    /**
     * Columns to leave out of a listing, and why, on each model that uses this.
     *
     * @return list<string>
     */
    abstract public function deferredColumns(): array;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutDeferredColumns(Builder $query): Builder
    {
        $table = $this->getTable();

        if (! array_key_exists($table, self::$selectableColumns)) {
            self::$selectableColumns[$table] = array_values(
                array_diff(Schema::getColumnListing($table), $this->deferredColumns()),
            );
        }

        return $query->select(array_map(
            static fn (string $column): string => $table.'.'.$column,
            self::$selectableColumns[$table],
        ));
    }
}
