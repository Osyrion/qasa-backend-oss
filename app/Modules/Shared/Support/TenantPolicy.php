<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds the tenant isolation policies (docs/plans/POSTGRES_RLS_PLAN.md).
 *
 * Every protected table gets the same policy under the same name, so what a
 * migration has to say is only which shape a table is: it owns its rows, it
 * reaches them through a parent, or it mixes per-account rows with shared
 * ones. Phases 2 and 3 wrote the SQL out by hand in each migration; there are
 * four more migrations across three modules now, and four copies of the role
 * quoting is how they drift.
 *
 * nullif() matters: current_setting(…, true) yields NULL when the variable was
 * never set, but an empty string once it has been cleared, and ''::uuid
 * raises. Both must read as "no account", which is what makes an unbound
 * connection see nothing rather than everything.
 */
final class TenantPolicy
{
    public const NAME = 'tenant_isolation';

    private const ACCOUNT = "nullif(current_setting('app.account_owner_id', true), '')::uuid";

    /**
     * A table whose rows carry the account themselves.
     *
     * $column defaults to user_id, the shape every owning table but one has.
     * The exception is `users`: the account is the row's own primary key,
     * not a foreign column on it — own('users', 'id') reads the same way.
     */
    public static function own(string $table, string $column = 'user_id'): void
    {
        self::create($table, self::identifier($column).' = '.self::ACCOUNT);
    }

    /**
     * A table whose rows reach their account through a parent.
     *
     * The check is bare existence, with no account comparison of its own:
     * reaching the parent means reading it, and the parent has a policy, so
     * a row belonging to another account is invisible here for exactly the
     * reason the parent is. The two cannot disagree.
     *
     * Measured on 20k invoices and 60k items, Postgres plans this as a hash
     * semi-join costing what a plain join does — 1,835 shared buffers either
     * way — so a denormalised user_id, its backfill, and the risk of it
     * drifting from the parent are all avoidable.
     */
    public static function viaParent(string $table, string $parent, string $foreignKey): void
    {
        self::create($table, sprintf(
            'EXISTS (SELECT 1 FROM %s WHERE %s.id = %s.%s)',
            self::identifier($parent),
            self::identifier($parent),
            self::identifier($table),
            self::identifier($foreignKey),
        ));
    }

    /**
     * A table mixing per-account rows with shared ones (user_id IS NULL).
     *
     * The shared rows are readable and writable by anyone, which is what they
     * already were: exchange rates are public reference data, and whichever
     * account needs a given day's rate first is the one that fetches and
     * stores it.
     */
    public static function ownOrShared(string $table): void
    {
        self::create($table, 'user_id IS NULL OR user_id = '.self::ACCOUNT);
    }

    public static function drop(string $table): void
    {
        DB::statement('DROP POLICY IF EXISTS '.self::NAME.' ON '.self::identifier($table));
        DB::statement('ALTER TABLE '.self::identifier($table).' DISABLE ROW LEVEL SECURITY');
    }

    /**
     * The role policies are attached to.
     *
     * Never PUBLIC: the owner runs migrations and the genuinely cross-account
     * paths, and RLS does not apply to a table's owner anyway, so there is
     * nothing here to make an exception for.
     */
    public static function role(): string
    {
        $role = (string) config('database.connections.pgsql.username');

        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe database role name: {$role}");
        }

        return '"'.$role.'"';
    }

    /**
     * WITH CHECK as well as USING, always. Without it a row could be inserted
     * for another account and merely not read back.
     */
    private static function create(string $table, string $predicate): void
    {
        DB::statement('ALTER TABLE '.self::identifier($table).' ENABLE ROW LEVEL SECURITY');
        DB::statement(sprintf(
            'CREATE POLICY %s ON %s FOR ALL TO %s USING (%s) WITH CHECK (%s)',
            self::NAME,
            self::identifier($table),
            self::role(),
            $predicate,
            $predicate,
        ));
    }

    private static function identifier(string $value): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $value) !== 1) {
            throw new RuntimeException("Unsafe identifier: {$value}");
        }

        return '"'.$value.'"';
    }
}
