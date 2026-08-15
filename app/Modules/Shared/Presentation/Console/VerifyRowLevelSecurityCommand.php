<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Deploy-time check that Row Level Security is actually in force.
 *
 * The second backstop of multi-tenancy is invisible when it fails. Postgres
 * ignores every policy for a superuser, for a role with BYPASSRLS, and for the
 * owner of the table — so a deployment that points DB_APP_USERNAME at the
 * owner runs with tenant isolation switched off, logs nothing, and looks
 * perfectly healthy (docs/plans/POSTGRES_RLS_PLAN.md).
 *
 * tests/Feature/Shared/RowLevelSecurityTest.php asserts the same three things,
 * but only ever against the test database. This is that check pointed at a
 * real one: run it from the deploy pipeline, after migrations, and let it fail
 * the deploy rather than the running application. It reads catalogs only.
 */
class VerifyRowLevelSecurityCommand extends Command
{
    protected $signature = 'qasa:rls:verify
        {--connection= : Connection to inspect, instead of the application default}';

    protected $description = 'Verify Row Level Security is in force: the serving role is subject to policies, and every tenant-owned table has them';

    /**
     * Tables that name an owning account yet are deliberately unprotected.
     *
     * Public so that RowLevelSecurityTest asserts against this very list
     * instead of a second copy of it — the repository has been bitten before
     * by two lists that were meant to agree and quietly stopped.
     *
     * @var array<string, string>
     */
    public const UNPROTECTED_BY_DESIGN = [
        // Billing, read across accounts by the Admin module on purpose. Not
        // auto-scoped in the application either — see the tenant scope
        // allowlist in tests/Architecture/TenantScopeTest.php.
        'subscriptions' => 'billing, read across accounts by Admin',
        'subscription_invoices' => 'billing, read across accounts by Admin',
        'subscription_orders' => 'billing, read across accounts by Admin',
        'subscription_payment_failures' => 'billing, written from the Stripe webhook and read across accounts by Admin',
        'subscription_usages' => 'usage metering, aggregated across accounts by Admin',
        'mrr_snapshots' => 'admin-only revenue aggregate, written by a scheduled command with no authenticated user and never read by a tenant',

        // Laravel's own session table, keyed by user_id but managed by the
        // framework and never read as tenant data.
        'sessions' => 'framework-managed',
    ];

    public function handle(): int
    {
        /** @var string|null $option */
        $option = $this->option('connection');
        $name = $option ?? (string) config('database.default');
        $connection = DB::connection($name);

        $driver = $connection->getDriverName();

        if ($driver !== 'pgsql') {
            // Not pedantry about the driver: RLS is a Postgres feature, so on
            // anything else there is no second backstop to verify at all.
            $this->error("Connection [{$name}] uses driver [{$driver}]. Row Level Security is PostgreSQL-only, so nothing here is enforced.");

            return self::FAILURE;
        }

        $ok = $this->verifyServingRole($connection, $name);
        $ok = $this->verifyEveryTenantTableIsProtected($connection) && $ok;
        $ok = $this->verifyNoPolicylessTable($connection) && $ok;

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The role must be one that policies apply to at all.
     */
    private function verifyServingRole(ConnectionInterface $connection, string $name): bool
    {
        /** @var object{role_name: string, is_superuser: bool, bypasses_rls: bool, owns_tables: int}|null $role */
        $role = $connection->selectOne("
            SELECT r.rolname AS role_name,
                   r.rolsuper AS is_superuser,
                   r.rolbypassrls AS bypasses_rls,
                   (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tableowner = current_user) AS owns_tables
            FROM pg_roles r
            WHERE r.rolname = current_user
        ");

        if ($role === null) {
            $this->error("Connection [{$name}]: could not read the current role from pg_roles.");

            return false;
        }

        $problems = [];

        if ($role->is_superuser) {
            $problems[] = 'is a superuser';
        }

        if ($role->bypasses_rls) {
            $problems[] = 'has BYPASSRLS';
        }

        if ($role->owns_tables > 0) {
            $problems[] = "owns {$role->owns_tables} table(s) in schema public";
        }

        if ($problems !== []) {
            $last = array_pop($problems);
            $listed = $problems === [] ? $last : implode(', ', $problems).' and '.$last;

            $this->error(sprintf(
                'Role [%s] on connection [%s] %s — every policy is silently inactive for it. Point DB_APP_USERNAME at the unprivileged role, not the owner.',
                $role->role_name,
                $name,
                $listed,
            ));

            return false;
        }

        $this->info("Serving role [{$role->role_name}]: policies apply (no superuser, no BYPASSRLS, owns no tables).");

        return true;
    }

    /**
     * A table naming an owning account must not exist without RLS enabled.
     *
     * Both column names, because both are used: user_id is the usual one,
     * owner_id is what team_invitations calls it.
     */
    private function verifyEveryTenantTableIsProtected(ConnectionInterface $connection): bool
    {
        /** @var list<object{relname: string}> $rows */
        $rows = $connection->select("
            SELECT DISTINCT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
              AND c.relrowsecurity = false
              AND a.attname IN ('user_id', 'owner_id')
              AND a.attnum > 0
              AND a.attisdropped = false
        ");

        $unprotected = array_values(array_filter(
            array_map(static fn (object $row): string => $row->relname, $rows),
            static fn (string $table): bool => ! array_key_exists($table, self::UNPROTECTED_BY_DESIGN),
        ));

        if ($unprotected !== []) {
            $this->error('Tenant-owned tables with no Row Level Security: '.implode(', ', $unprotected).'.');

            return false;
        }

        $this->info('Tenant-owned tables: all protected.');

        return true;
    }

    /**
     * RLS on with no policy is the opposite failure — it denies everything.
     *
     * It cannot leak, but it takes the feature down as surely as a missing
     * policy leaks, and a deploy is where that is cheap to find out.
     */
    private function verifyNoPolicylessTable(ConnectionInterface $connection): bool
    {
        /** @var list<object{relname: string}> $rows */
        $rows = $connection->select("
            SELECT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
              AND c.relrowsecurity = true
              AND NOT EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid)
        ");

        if ($rows !== []) {
            $tables = implode(', ', array_map(static fn (object $row): string => $row->relname, $rows));
            $this->error("Row Level Security is enabled with no policy on: {$tables}. Those tables read as empty and reject every write.");

            return false;
        }

        $this->info('Protected tables: all carry at least one policy.');

        return true;
    }
}
