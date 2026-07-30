<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Trigram indexes backing the client and order list search.
     *
     * Two different things happen here:
     *
     * - email and ico get plain trigram indexes. The queries do not change;
     *   a GIN trigram index simply lets Postgres serve the existing
     *   ILIKE '%term%' without a sequential scan, which a B-tree cannot do
     *   for a leading wildcard.
     * - name, surname and company_name get functional indexes over
     *   immutable_unaccent(lower(...)). These only work in tandem with the
     *   query: a functional index serves nothing unless the query repeats
     *   the identical expression, which is why that expression lives in
     *   Shared\Support\Search rather than being spelled out per repository.
     *
     * GIN costs more per write than B-tree. clients and orders are read-heavy
     * (a handful of writes per user per day against list views hit on every
     * page load), so that trade is clearly worth it here — worth revisiting
     * if either table ever takes bulk imports.
     */
    public function up(): void
    {
        foreach ($this->indexes() as $name => $definition) {
            DB::statement("CREATE INDEX {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->indexes()) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }

    /**
     * @return array<string, string>
     */
    private function indexes(): array
    {
        $unaccented = [
            'clients_name_unaccent_trgm_idx' => ['clients', 'name'],
            'clients_surname_unaccent_trgm_idx' => ['clients', 'surname'],
            'clients_company_name_unaccent_trgm_idx' => ['clients', 'company_name'],
            'orders_name_unaccent_trgm_idx' => ['orders', 'name'],
        ];

        $indexes = [];

        foreach ($unaccented as $name => [$table, $column]) {
            $indexes[$name] = "{$table} USING gin (public.immutable_unaccent(lower({$column})) gin_trgm_ops)";
        }

        $indexes['clients_email_trgm_idx'] = 'clients USING gin (email gin_trgm_ops)';
        $indexes['clients_ico_trgm_idx'] = 'clients USING gin (ico gin_trgm_ops)';

        return $indexes;
    }
};
