<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoice_inbox_items DROP CONSTRAINT IF EXISTS invoice_inbox_items_status_check');
            DB::statement(
                "ALTER TABLE invoice_inbox_items ADD CONSTRAINT invoice_inbox_items_status_check CHECK (status::text IN ('processing', 'pending', 'imported', 'ignored', 'failed'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoice_inbox_items DROP CONSTRAINT IF EXISTS invoice_inbox_items_status_check');
            DB::statement(
                "ALTER TABLE invoice_inbox_items ADD CONSTRAINT invoice_inbox_items_status_check CHECK (status::text IN ('pending', 'imported', 'ignored', 'failed'))"
            );
        }
    }
};
