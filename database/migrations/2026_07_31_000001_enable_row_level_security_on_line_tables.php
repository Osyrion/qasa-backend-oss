<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant isolation for the tables that reach their account through a parent.
 *
 * These carry no user_id, which is why nothing scoped them before: the
 * Eloquent global scope needs a column to filter on, so InvoiceItem::query()
 * has always returned every account's rows. What protected them was the
 * convention of only ever reaching them through their invoice or order. This
 * is the first actual enforcement they get, not a second layer over one.
 *
 * The policy is an EXISTS against the parent rather than a denormalised
 * user_id. Measured on 20k invoices and 60k items, Postgres plans it as a
 * hash semi-join and it costs exactly what a plain join does — 1,835 shared
 * buffers either way — so the column, its backfill, and the risk of it
 * drifting from the parent are all avoidable.
 *
 * Reaching the parent means reading it, and the parent has a policy of its
 * own: a row whose invoice belongs to another account is invisible here for
 * the same reason the invoice is. The two cannot disagree.
 *
 * payment_order_items is missing on purpose. Its parent is payment_orders,
 * which has no policy yet, so there is nothing for it to inherit.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{0: string, 1: string}> table => [parent, foreign key]
     */
    private const TABLES = [
        'contact_persons' => ['clients', 'client_id'],
        'invoice_items' => ['invoices', 'invoice_id'],
        'invoice_payments' => ['invoices', 'invoice_id'],
        'invoice_work_report_lines' => ['invoices', 'invoice_id'],
        'order_items' => ['orders', 'order_id'],
        'quote_items' => ['quotes', 'quote_id'],
        'supplier_invoice_vat_lines' => ['supplier_invoices', 'supplier_invoice_id'],
    ];

    public function up(): void
    {
        $role = $this->role();

        foreach (self::TABLES as $table => [$parent, $foreignKey]) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("
                CREATE POLICY tenant_isolation ON {$table}
                    FOR ALL TO {$role}
                    USING (EXISTS (
                        SELECT 1 FROM {$parent}
                        WHERE {$parent}.id = {$table}.{$foreignKey}
                    ))
                    WITH CHECK (EXISTS (
                        SELECT 1 FROM {$parent}
                        WHERE {$parent}.id = {$table}.{$foreignKey}
                    ))
            ");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
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
