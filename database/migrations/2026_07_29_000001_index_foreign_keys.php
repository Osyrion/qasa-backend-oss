<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for foreign keys that had none.
 *
 * Unlike MySQL, PostgreSQL does not create an index for a foreign key. Every
 * one of these has ON DELETE CASCADE or SET NULL, so without an index each
 * delete of a parent row makes Postgres scan the whole child table to find
 * what to cascade to. Deleting 60k invoices on a seeded database did not
 * finish in five minutes, because three of the constraints below point back
 * at invoices.
 *
 * Only the constraints whose parent is deleted in normal use are covered.
 * Lookup tables that effectively never lose rows — subscription plans, roles,
 * admin users — are left alone; a scan of a table with a handful of rows
 * costs nothing, and an index there would only slow writes down.
 *
 * Constraints owned by premium modules are indexed by those modules, next to
 * the constraint itself, even when the column sits on a core table.
 */
return new class extends Migration
{
    /**
     * @var array<string, string> table => column
     */
    private const INDEXES = [
        'invoices_settled_invoice_id_index' => 'invoices.settled_invoice_id',
        'invoices_related_invoice_id_index' => 'invoices.related_invoice_id',
        'invoices_bank_account_id_index' => 'invoices.bank_account_id',
        'invoices_recurring_template_id_index' => 'invoices.recurring_template_id',
        'quotes_converted_invoice_id_index' => 'quotes.converted_invoice_id',
        'quotes_converted_order_id_index' => 'quotes.converted_order_id',
        'invoice_items_order_item_id_index' => 'invoice_items.order_item_id',
        'invoice_inbox_items_matched_client_id_index' => 'invoice_inbox_items.matched_client_id',
        'invoice_inbox_items_supplier_invoice_id_index' => 'invoice_inbox_items.supplier_invoice_id',
        'order_attachments_user_id_index' => 'order_attachments.user_id',
        'order_notes_user_id_index' => 'order_notes.user_id',
        'activity_log_actor_id_index' => 'activity_log.actor_id',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $target) {
            [$table, $column] = explode('.', $target);

            Schema::table($table, function (Blueprint $blueprint) use ($column, $name): void {
                $blueprint->index($column, $name);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => $target) {
            [$table] = explode('.', $target);

            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropIndex($name);
            });
        }
    }
};
