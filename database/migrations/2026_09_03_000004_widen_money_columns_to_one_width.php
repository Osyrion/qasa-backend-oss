<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One width for money.
 *
 * The same concept was declared three ways: an invoice total was
 * numeric(10,2), the supplier invoice it settles was numeric(12,2), and a cash
 * document was numeric(15,2). Two sides of one document disagreeing about how
 * large a number can be is not a rounding detail — numeric(10,2) tops out at
 * 99,999,999.99, which in CZK is about four million euro, and an invoice whose
 * lines sum past it does not round or truncate: Postgres raises `numeric field
 * overflow` and the request 500s. It would fail on the customer side and
 * succeed on the supplier side of the same trade.
 *
 * Postgres stores numeric as a variable-length value, so the declared
 * precision is a constraint and not a size — widening costs nothing per row,
 * and this is a metadata-only change: the typmod moves, no table is rewritten
 * and no lock is held for longer than the catalogue update (verified against
 * a 200k-row table before writing this).
 *
 * What is deliberately left alone:
 * - numeric(5,2) percentages (vat_rate, discount_percent, avg_consumption) and
 *   numeric(6,2)/numeric(8,2) hours. Not money; their range is the point.
 * - The statutory tables (cz_/sk_tax_rate_parameters) at numeric(12,2). Those
 *   are amounts fixed by legislation — a credit, a threshold — not amounts a
 *   user can enter, and they are bounded by something other than our schema.
 */
return new class extends Migration
{
    private const WIDTH = '15,2';

    private const PREVIOUS = [
        'expenses.amount' => '10,2',
        'invoice_items.total_excl_vat' => '10,2',
        'invoice_items.total_incl_vat' => '10,2',
        'invoice_items.unit_price' => '10,2',
        'invoice_items.vat_amount' => '10,2',
        'invoice_payments.amount' => '10,2',
        'invoices.discount_amount' => '10,2',
        'invoices.subtotal' => '10,2',
        'invoices.total' => '10,2',
        'invoices.vat_amount' => '10,2',
        'order_items.total_excl_vat' => '10,2',
        'order_items.total_incl_vat' => '10,2',
        'order_items.unit_price' => '10,2',
        'order_items.vat_amount' => '10,2',
        'orders.estimated_price' => '10,2',
        'orders.rate' => '10,2',
        'price_list_items.unit_price' => '10,2',
        'quote_items.total_excl_vat' => '10,2',
        'quote_items.total_incl_vat' => '10,2',
        'quote_items.unit_price' => '10,2',
        'quote_items.vat_amount' => '10,2',
        'quotes.discount_amount' => '10,2',
        'quotes.subtotal' => '10,2',
        'quotes.total' => '10,2',
        'quotes.vat_amount' => '10,2',
        'rates.rate' => '10,2',
        'recurring_invoice_template_items.unit_price' => '10,2',
        'subscription_invoices.gross_amount' => '10,2',
        'subscription_invoices.net_amount' => '10,2',
        'subscription_invoices.vat_amount' => '10,2',
        'subscription_orders.amount' => '10,2',
        'subscription_plan_prices.price' => '10,2',
        'time_entries.rate_override' => '10,2',
        'bank_statement_suggestions.amount' => '12,2',
        'contribution_payments.amount' => '12,2',
        'mrr_snapshots.mrr' => '12,2',
        'operating_costs.amount' => '12,2',
        'payment_order_items.amount' => '12,2',
        'payment_orders.total_amount' => '12,2',
        'subscription_payment_failures.amount' => '12,2',
        'supplier_invoice_vat_lines.base' => '12,2',
        'supplier_invoice_vat_lines.vat_amount' => '12,2',
        'supplier_invoices.self_assessed_vat_amount' => '12,2',
        'supplier_invoices.subtotal' => '12,2',
        'supplier_invoices.total' => '12,2',
        'supplier_invoices.vat_amount' => '12,2',
        'users.automation_amount_limit' => '12,2',
    ];

    public function up(): void
    {
        foreach (array_keys(self::PREVIOUS) as $target) {
            self::retype($target, self::WIDTH);
        }
    }

    /**
     * Narrowing can fail on data that has since been written, which is the
     * correct outcome for a rollback: it says the value no longer fits rather
     * than silently rounding it away.
     */
    public function down(): void
    {
        foreach (self::PREVIOUS as $target => $width) {
            self::retype($target, $width);
        }
    }

    /**
     * A premium module's tables are absent in the generated core, so every
     * column is applied only where it exists — one list works for both
     * editions, and a table that is present but missed would still show up in
     * the money-width test.
     */
    private static function retype(string $target, string $width): void
    {
        [$table, $column] = explode('.', $target);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE numeric({$width})");
    }
};
