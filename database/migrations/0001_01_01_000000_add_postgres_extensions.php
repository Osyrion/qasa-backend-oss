<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Postgres extensions the schema depends on, plus the IMMUTABLE unaccent
     * wrapper the trigram search indexes are built on.
     *
     * This runs first (0001_ prefix) because everything else may depend on it:
     * an expression index referencing gin_trgm_ops or immutable_unaccent()
     * cannot be created before the extension exists.
     *
     * Provisioned by migration rather than docker/postgres/init.sql because
     * init.sql only reaches the dev database — the test database is cloned
     * from template0 (guaranteed extension-free) and CI's postgres service
     * containers are bare images with no init script at all. All three are
     * "trusted" extensions since PG13, so the database owner can create them
     * without superuser rights.
     *
     * Deliberately unguarded: this application is PostgreSQL-only. Pointing it
     * at another driver should fail loudly here rather than silently skip and
     * break later on a missing operator class.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS "pg_trgm"');
        DB::statement('CREATE EXTENSION IF NOT EXISTS "unaccent"');
        DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');

        // unaccent() is STABLE, not IMMUTABLE (it depends on a text-search
        // dictionary that can be reloaded), so Postgres refuses it inside an
        // index expression. The two-argument form pins the dictionary
        // explicitly, which makes the result deterministic and legal to
        // declare IMMUTABLE.
        //
        // Everything is schema-qualified on purpose: an expression index only
        // matches a query expression that resolves to the same function, so a
        // differing search_path between the migrating role and the app role
        // would silently stop the index from being used.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.immutable_unaccent(text)
            RETURNS text
            LANGUAGE sql
            IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent', $1) $$
        SQL);
    }

    public function down(): void
    {
        // Intentionally a no-op. DROP EXTENSION would break every dependent
        // object, and immutable_unaccent() is carried by the search indexes
        // created in later migrations — Laravel rolls back in batches, so
        // dropping the function here could leave those indexes dangling.
    }
};
