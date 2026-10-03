<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A cursored companion to tenant_account_ids().
 *
 * TenantContext::forEachAccount() is how every scheduled job crosses accounts
 * under Row Level Security, and it read the whole account list into memory in
 * one go — a sequential scan of `users` plus a sort, and an array that grows
 * with the customer base for as long as the job runs.
 *
 * This takes a cursor and a limit instead, so the walk is bounded on both
 * sides: memory holds one page, and each page is an index range scan resumed
 * where the last one stopped, so the whole walk still costs one pass over the
 * index rather than one scan per page.
 *
 * Keyset, not LIMIT/OFFSET: the set is ordered by the account id itself, so a
 * page is "the next n after this one" and no account can be skipped or seen
 * twice because another was created while the job ran.
 *
 * The core edition has no team membership, so an account *is* a users row and
 * `id` is the account. The SaaS edition replaces this body with the
 * coalesce(owner_id, id) form its own migration uses — exactly the split
 * tenant_account_ids() already has, and for the same reason: owner_id is a
 * column only that edition has, so naming it here would stop the generated
 * core from migrating at all.
 *
 * tenant_account_ids() stays: qasa:rls:verify and the phase 7 policies use it,
 * and it is the honest way to ask "how many accounts are there".
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = (string) config('database.connections.pgsql.username');

        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe database role name: {$role}");
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.tenant_account_ids_after(p_after uuid, p_limit integer)
            RETURNS SETOF uuid
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public
            AS $$
                SELECT id AS account
                FROM users
                WHERE p_after IS NULL OR id > p_after
                ORDER BY 1
                LIMIT p_limit
            $$
        SQL);

        DB::statement('REVOKE ALL ON FUNCTION public.tenant_account_ids_after(uuid, integer) FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION public.tenant_account_ids_after(uuid, integer) TO \"{$role}\"");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.tenant_account_ids_after(uuid, integer)');
    }
};
