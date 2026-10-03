<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Nothing but the application may call a function that reads across tenants.
 *
 * A SECURITY DEFINER function runs as its owner, and the owner is not subject
 * to row level security — that is the whole reason these functions exist. But
 * Postgres grants EXECUTE on every new function to PUBLIC, so a `GRANT … TO`
 * the application role restricts nothing on its own: every role on the server
 * could still read the cross-account totals, or resolve a token to an account.
 * The `REVOKE ALL … FROM PUBLIC` is the half that does the work, and it is the
 * half seven functions were created without.
 */
it('grants no SECURITY DEFINER function to PUBLIC', function (): void {
    $exposed = DB::table('pg_proc as p')
        ->join('pg_namespace as n', 'n.oid', '=', 'p.pronamespace')
        ->where('n.nspname', 'public')
        ->where('p.prosecdef', true)
        // A null ACL is not "no grants": it is the default, which includes
        // EXECUTE for PUBLIC.
        ->where(fn ($query) => $query
            ->whereNull('p.proacl')
            ->orWhereRaw("EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE')"))
        ->orderBy('p.proname')
        ->pluck('p.proname')
        ->all();

    expect($exposed)->toBe([]);
});
