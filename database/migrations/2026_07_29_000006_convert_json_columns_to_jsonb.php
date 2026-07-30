<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Converts the core json columns to jsonb.
 *
 * json stores the document as received and re-parses it on every read; jsonb
 * stores it decomposed, which makes reads cheaper and is the only form that
 * can be indexed or queried with the containment and path operators.
 *
 * The trade is that jsonb does not preserve key order, drops duplicate keys
 * and normalises whitespace. Nothing here depends on any of that: every
 * column is decoded into a PHP array before use, and none of them is hashed,
 * signed or replayed verbatim.
 *
 * No GIN indexes come with this — nothing queries inside these documents yet,
 * and an unused GIN index is pure write cost. The point is to make that
 * option available.
 *
 * idempotency_keys.response_body stays json on purpose. It is a stored HTTP
 * response replayed to whoever repeats the request, and the promise of an
 * idempotent endpoint is that the repeat gets the same answer — reordering
 * its keys would keep that true in JSON terms but not in spirit. Nothing
 * would ever query inside it either, so there is nothing to gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE activity_log ALTER COLUMN changes TYPE jsonb USING changes::jsonb');
        DB::statement('ALTER TABLE invoice_inbox_items ALTER COLUMN suggestions TYPE jsonb USING suggestions::jsonb');
        DB::statement('ALTER TABLE invoices ALTER COLUMN bank_account_snapshot TYPE jsonb USING bank_account_snapshot::jsonb');
        DB::statement('ALTER TABLE invoices ALTER COLUMN client_snapshot TYPE jsonb USING client_snapshot::jsonb');
        DB::statement('ALTER TABLE invoices ALTER COLUMN emailed_cc TYPE jsonb USING emailed_cc::jsonb');
        DB::statement('ALTER TABLE invoices ALTER COLUMN supplier_snapshot TYPE jsonb USING supplier_snapshot::jsonb');
        DB::statement('ALTER TABLE quotes ALTER COLUMN client_snapshot TYPE jsonb USING client_snapshot::jsonb');
        DB::statement('ALTER TABLE quotes ALTER COLUMN supplier_snapshot TYPE jsonb USING supplier_snapshot::jsonb');
        DB::statement('ALTER TABLE supplier_invoices ALTER COLUMN vendor_snapshot TYPE jsonb USING vendor_snapshot::jsonb');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE activity_log ALTER COLUMN changes TYPE json USING changes::json');
        DB::statement('ALTER TABLE invoice_inbox_items ALTER COLUMN suggestions TYPE json USING suggestions::json');
        DB::statement('ALTER TABLE invoices ALTER COLUMN bank_account_snapshot TYPE json USING bank_account_snapshot::json');
        DB::statement('ALTER TABLE invoices ALTER COLUMN client_snapshot TYPE json USING client_snapshot::json');
        DB::statement('ALTER TABLE invoices ALTER COLUMN emailed_cc TYPE json USING emailed_cc::json');
        DB::statement('ALTER TABLE invoices ALTER COLUMN supplier_snapshot TYPE json USING supplier_snapshot::json');
        DB::statement('ALTER TABLE quotes ALTER COLUMN client_snapshot TYPE json USING client_snapshot::json');
        DB::statement('ALTER TABLE quotes ALTER COLUMN supplier_snapshot TYPE json USING supplier_snapshot::json');
        DB::statement('ALTER TABLE supplier_invoices ALTER COLUMN vendor_snapshot TYPE json USING vendor_snapshot::json');
    }
};
