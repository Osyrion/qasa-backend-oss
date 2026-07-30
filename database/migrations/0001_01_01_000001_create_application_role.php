<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The unprivileged role the application serves requests as.
 *
 * Row Level Security is invisible to two kinds of role: a superuser, and the
 * owner of the table. Today's connection is both, so policies would do
 * nothing at all until this exists — which is why the role split comes first,
 * before any policy is written (docs/plans/POSTGRES_RLS_PLAN.md).
 *
 * Migrations keep running as the owner, so DDL and CREATE EXTENSION are
 * unaffected, and the owner keeps its RLS bypass for the paths that are
 * legitimately cross-account. Only the runtime connection drops to this role.
 *
 * Runs first (0001_ prefix, right after the extensions) because ALTER DEFAULT
 * PRIVILEGES only reaches objects created after it. Tables created by earlier
 * migrations in the same run would come out ungranted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = $this->role();

        // No placeholders: neither CREATE ROLE nor a DO block accepts bound
        // parameters. Both values come from config rather than a request, and
        // are quoted the way Postgres itself does it — doubling the quote
        // character — with the role name checked against a bare identifier.
        $this->contended(fn () => DB::statement(sprintf(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = %s) THEN
                    CREATE ROLE %s LOGIN PASSWORD %s;
                END IF;
            END
            $$
        SQL, $this->literal($role), $this->identifier($role), $this->literal($this->password()))));

        // Belt and braces: a role created by hand elsewhere might not have
        // been created with these. Only touched when one is actually wrong,
        // so the usual run leaves the shared catalog row alone.
        $this->contended(fn () => DB::statement(sprintf(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM pg_roles
                    WHERE rolname = %s
                      AND (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole)
                ) THEN
                    ALTER ROLE %s NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
                END IF;
            END
            $$
        SQL, $this->literal($role), $this->identifier($role))));

        DB::statement("GRANT USAGE ON SCHEMA public TO {$this->identifier($role)}");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$this->identifier($role)}");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$this->identifier($role)}");

        // Everything the later migrations create.
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$this->identifier($role)}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$this->identifier($role)}");
    }

    public function down(): void
    {
        $role = $this->identifier($this->role());

        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM {$role}");
        DB::statement("REVOKE ALL ON ALL TABLES IN SCHEMA public FROM {$role}");
        DB::statement("REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM {$role}");
        DB::statement("REVOKE USAGE ON SCHEMA public FROM {$role}");

        // The role itself stays. It is cluster-level, may own nothing here,
        // and dropping it would break any other database granting to it.
    }

    /**
     * Run a statement that touches the cluster-wide role catalog.
     *
     * Roles live outside any one database, so `artisan test --parallel`
     * migrating four databases at once has four connections writing the same
     * pg_authid row and Postgres rejects the losers with "tuple concurrently
     * updated". There is nothing to serialise on — advisory locks are
     * per-database — so the conflict is simply waited out.
     *
     * @param  callable(): mixed  $statement
     */
    private function contended(callable $statement): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $statement();

                return;
            } catch (QueryException $e) {
                if ($attempt >= 5 || ! $this->isCatalogConflict($e)) {
                    throw $e;
                }

                usleep(random_int(50_000, 200_000));
            }
        }
    }

    private function isCatalogConflict(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'tuple concurrently updated')
            || str_contains($e->getMessage(), 'already exists');
    }

    private function role(): string
    {
        $role = (string) config('database.connections.pgsql.username');

        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new RuntimeException("Unsafe database role name: {$role}");
        }

        return $role;
    }

    private function password(): string
    {
        return (string) config('database.connections.pgsql.password');
    }

    private function identifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
