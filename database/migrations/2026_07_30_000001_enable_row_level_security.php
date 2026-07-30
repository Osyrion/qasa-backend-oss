<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant isolation, enforced by the database.
 *
 * Until now it has been one Eloquent global scope deep, which holds only for
 * queries that go through the model — 83 call sites reapply the filter by
 * hand, and raw SQL never gets it at all. A policy holds regardless of which
 * code path built the query (docs/plans/POSTGRES_RLS_PLAN.md).
 *
 * Only the tables that carry user_id themselves. Line item tables reach their
 * account through a parent and are covered separately.
 *
 * Policies are attached to the application role, not to PUBLIC. The owner —
 * which runs migrations and the handful of genuinely cross-account paths —
 * bypasses RLS by virtue of owning the tables, so no FORCE is used and
 * nothing here needs an exception.
 *
 * nullif() matters: current_setting(…, true) yields NULL when the variable
 * was never set, but an empty string once it has been cleared, and ''::uuid
 * raises. Both must read as "no account", which is what makes an unbound
 * connection see nothing rather than everything.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = [
        'invoices',
        'clients',
        'orders',
        'quotes',
        'supplier_invoices',
        'expenses',
    ];

    public function up(): void
    {
        $role = $this->role();

        // Public document links have no authenticated user, so nothing binds
        // the connection and the policy would hide the very row the link is
        // for. This is the one deliberate way through it: SECURITY DEFINER
        // runs as the owner, which RLS does not apply to.
        //
        // It has to be a function rather than a query on the owner
        // connection, because a separate connection cannot see an open
        // transaction — which is exactly what every test runs inside.
        //
        // The surface is deliberately tiny: give it a token, get back an
        // account id. The token is the credential the recipient already
        // holds, the table name is checked against a fixed list, and nothing
        // but the id comes back.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.account_for_public_document(p_table text, p_token text)
            RETURNS uuid
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public
            AS $$
            DECLARE
                v_owner uuid;
            BEGIN
                IF p_table NOT IN ('invoices', 'quotes') THEN
                    RAISE EXCEPTION 'unsupported public document table: %', p_table;
                END IF;

                EXECUTE format('SELECT user_id FROM %I WHERE public_token = $1', p_table)
                    INTO v_owner USING p_token;

                RETURN v_owner;
            END
            $$
        SQL);

        DB::statement('REVOKE ALL ON FUNCTION public.account_for_public_document(text, text) FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION public.account_for_public_document(text, text) TO {$role}");

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("
                CREATE POLICY tenant_isolation ON {$table}
                    FOR ALL TO {$role}
                    USING (user_id = nullif(current_setting('app.account_owner_id', true), '')::uuid)
                    WITH CHECK (user_id = nullif(current_setting('app.account_owner_id', true), '')::uuid)
            ");
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.account_for_public_document(text, text)');

        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }

    private function role(): string
    {
        $role = (string) config('database.connections.pgsql.username');

        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe database role name: {$role}");
        }

        return '"'.$role.'"';
    }
};
