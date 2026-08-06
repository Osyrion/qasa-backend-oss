<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One durable link per client, rather than one per document.
 *
 * The lookup needs the same escape hatch the per-document links already use:
 * a portal request is unauthenticated, so nothing binds the connection, and
 * under Row Level Security an unbound connection finds no row at all. A
 * SECURITY DEFINER function resolves token → owner and returns nothing else;
 * everything after that runs inside the policy like any other request.
 *
 * Separate from account_for_public_document() rather than another branch in
 * it: that one is keyed on a `public_token` column across two document
 * tables, this one is a different column on a third, and merging them would
 * mean a table-name parameter that no longer constrains anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('portal_token', 64)->nullable()->unique()
                ->comment('Grants the client read-only access to their own invoices; null = portal off');
        });

        $role = (string) config('database.connections.pgsql.username');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.account_for_client_portal(p_token text)
            RETURNS uuid
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public
            AS $$
            DECLARE
                v_owner uuid;
            BEGIN
                SELECT user_id INTO v_owner FROM clients WHERE portal_token = p_token;

                RETURN v_owner;
            END
            $$
        SQL);

        DB::statement('REVOKE ALL ON FUNCTION public.account_for_client_portal(text) FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION public.account_for_client_portal(text) TO {$role}");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.account_for_client_portal(text)');

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('portal_token');
        });
    }
};
