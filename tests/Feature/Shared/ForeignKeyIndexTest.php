<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Every foreign key needs an index it can lead.
 *
 * Postgres indexes the *referenced* side automatically (it has to be unique)
 * and the referencing side not at all. Without one, deleting or updating a
 * parent row scans the whole child table to prove no child points at it — so
 * deleting one client scanned every quote on the instance, and the cost grows
 * with the table rather than with the row being deleted.
 *
 * "Leads" rather than "is covered by": a composite index on (a, b) serves a
 * foreign key on a, but not one on b. That is why a pivot table with a
 * composite primary key still needs a second index for its other column.
 */
it('has an index leading every foreign key', function (): void {
    // Vendor-owned schema this repo does not author. spatie's pivot carries
    // a composite primary key that leads with permission_id; role_id has no
    // index of its own, and the fix belongs upstream, not in a migration
    // that would fight the package's own.
    $allowed = [
        'role_has_permissions.role_id' => 'spatie/laravel-permission owns this table',
    ];

    /** @var list<object{ref: string}> $unindexed */
    $unindexed = DB::select(<<<'SQL'
        SELECT c.conrelid::regclass::text || '.' || a.attname AS ref
        FROM pg_constraint c
        JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
        WHERE c.contype = 'f'
          AND c.connamespace = 'public'::regnamespace
          AND NOT EXISTS (
              SELECT 1 FROM pg_index i
              WHERE i.indrelid = c.conrelid AND i.indkey[0] = c.conkey[1]
          )
        ORDER BY 1
    SQL);

    $missing = collect($unindexed)
        ->pluck('ref')
        ->reject(fn (string $ref): bool => array_key_exists($ref, $allowed))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});
